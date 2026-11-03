<?php

namespace App\Services;

use App\Concerns\ReadsTranslatedTaxonomy;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Every read path for the catalogue lives here, so filtering/sorting rules are
 * defined once and reused by the storefront, the search box and the admin.
 */
class CatalogService
{
    use ReadsTranslatedTaxonomy;

    public const SORTS = [
        'featured' => 'featured',
        'newest' => 'newest',
        'price_asc' => 'price_asc',
        'price_desc' => 'price_desc',
        'name' => 'name',
    ];

    /**
     * Bounds answer five aggregate questions about the whole catalogue, and
     * every listing response pays for them. They only move when the owner
     * writes a product, brand or category, so the answer is cached and the
     * admin writes flush it. The TTL is the backstop for a missed flush, not
     * the mechanism — same contract as `NavigationService`.
     */
    private const BOUNDS_TTL_SECONDS = 900;

    private const BOUNDS_CACHE_KEY = 'catalog:bounds';

    /**
     * The cached catalogue listing.
     *
     * A listing is the storefront's busiest read. The homepage shows the whole
     * catalogue under its hero, the shop's own page is that same list with the
     * filters on it, and the "newest" list is that same list under another
     * sort. Each of those is a products query, a count query and four eager
     * loads — translations, images, brand, categories — every single time.
     *
     * The answer only moves when the owner writes to the catalogue, so it is
     * cached and the admin writes drop it, exactly like the bounds and the
     * homepage payload. The TTL is the backstop for a write path that is ever
     * missed, not the primary mechanism — same contract as `NavigationService`.
     */
    private const LISTING_TTL_SECONDS = 900;

    private const LISTING_CACHE_PREFIX = 'catalog:listing:';

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= (int) config('chamma.catalog.per_page');
        $locale = $filters['locale'] ?? App::getLocale();

        // The page the shopper asked for, resolved the same way the paginator
        // resolves it, so the cached listing and a live one are the same page.
        $page = Paginator::resolveCurrentPage('page');

        $key = $this->listingCacheKey($locale, $filters, $perPage, $page);

        if ($key !== null) {
            $cached = Cache::get($key);

            if (is_array($cached) && isset($cached['items'], $cached['total'])) {
                return $this->paginatorFromCache($cached['items'], (int) $cached['total'], $perPage, $page);
            }
        }

        $paginator = $this->query($filters, $locale)->paginate($perPage)->withQueryString();

        if ($key !== null) {
            Cache::put($key, [
                'items' => $paginator->items(),
                'total' => $paginator->total(),
            ], self::LISTING_TTL_SECONDS);
        }

        return $paginator;
    }

    /**
     * The cache key for a listing, or null when the listing is not cached.
     *
     * A listing is identified by its filters, and most of them are open-ended:
     * a search term is whatever the shopper typed, a price bound is whatever
     * they typed into a box, and a category or a brand slug is not proved to
     * exist by the request that carries it. Caching any of those would mean a
     * cache key per thing a shopper tried — an unbounded key space, and one
     * that can be filled on purpose.
     *
     * What is left is the catalogue's own default view: no filter but the
     * sort. That is the listing every storefront page asks for — the
     * homepage's catalogue, the shop's page, the newest-first list — so it is
     * where the cache earns its keep.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listingCacheKey(string $locale, array $filters, int $perPage, int $page): ?string
    {
        // Only the sort may vary. `locale` is the other key the filters carry,
        // and it is already part of the key.
        if (array_diff(array_keys($filters), ['sort', 'locale']) !== []) {
            return null;
        }

        $sort = (string) ($filters['sort'] ?? 'featured');

        if (! array_key_exists($sort, self::SORTS)) {
            return null;
        }

        if (! in_array($perPage, (array) config('chamma.catalog.cacheable_per_page'), true)) {
            return null;
        }

        if ($page < 1 || $page > (int) config('chamma.catalog.cacheable_pages')) {
            return null;
        }

        return $this->listingKey($locale, $sort, $perPage, $page);
    }

    /**
     * The key a listing is stored under.
     *
     * Every dimension of it is bounded — the locales the shop publishes in, the
     * sorts it offers, the page sizes it is cached at, the first pages of a
     * listing — which is what lets a flush enumerate the whole key space
     * instead of guessing at it.
     */
    private function listingKey(string $locale, string $sort, int $perPage, int $page): string
    {
        return self::LISTING_CACHE_PREFIX.$locale.'|'.$sort.'|'.$perPage.'|'.$page;
    }

    /**
     * Every listing key that can exist.
     *
     * @return array<int, string>
     */
    private function listingCacheKeys(): array
    {
        $keys = [];

        foreach (array_keys(config('chamma.locales')) as $locale) {
            foreach (array_keys(self::SORTS) as $sort) {
                foreach ((array) config('chamma.catalog.cacheable_per_page') as $perPage) {
                    for ($page = 1; $page <= (int) config('chamma.catalog.cacheable_pages'); $page++) {
                        $keys[] = $this->listingKey((string) $locale, (string) $sort, (int) $perPage, $page);
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Rebuild a paginator from a cached listing.
     *
     * Built fresh rather than cached whole, because a paginator carries the
     * URLs of the page it was made for: the path and the query string of the
     * request that filled the cache. One that came out of the cache with
     * yesterday's query string would page a shopper into the wrong list, and
     * the path of the host the cache was filled from would follow it there.
     *
     * @param  array<int, Product>  $items
     */
    private function paginatorFromCache(array $items, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return (new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        ))->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function query(array $filters = [], ?string $locale = null): Builder
    {
        $locale ??= App::getLocale();

        $query = Product::query()
            ->active()
            ->with([
                'translations',
                'images',
                'brand.translations',
                'categories.translations',
            ]);

        // `products.price` is already the payable amount: `compare_at_price`
        // only ever holds the crossed-out reference price. Filtering on it
        // would make a discounted product appear in the wrong price bucket.
        $effectivePrice = 'products.price';

        if ($this->filled($filters, 'on_sale') && $filters['on_sale']) {
            $query->whereNotNull('products.compare_at_price')
                ->whereColumn('products.compare_at_price', '>', 'products.price');
        }

        if ($this->filled($filters, 'in_stock') && $filters['in_stock']) {
            $query->inStock();
        }

        if ($this->filled($filters, 'category')) {
            $query->whereHas('categories', fn ($q) => $q
                ->where('categories.slug', $filters['category'])
                ->orWhere('categories.id', $this->numeric($filters['category'] ?? null)));
        }

        if ($this->filled($filters, 'brand')) {
            $query->whereHas('brand', fn ($q) => $q
                ->where('brands.slug', $filters['brand'])
                ->orWhere('brands.id', $this->numeric($filters['brand'] ?? null)));
        }

        if ($this->filled($filters, 'gender')) {
            $query->whereIn('products.gender', (array) $filters['gender']);
        }

        if ($this->filled($filters, 'min_price')) {
            $query->whereRaw("{$effectivePrice} >= ?", [(float) $filters['min_price']]);
        }

        if ($this->filled($filters, 'max_price')) {
            $query->whereRaw("{$effectivePrice} <= ?", [(float) $filters['max_price']]);
        }

        if ($this->filled($filters, 'search')) {
            $query->search((string) $filters['search']);
        }

        if ($this->filled($filters, 'featured') && $filters['featured']) {
            $query->featured();
        }

        return $this->sort($query, (string) ($filters['sort'] ?? 'featured'), $locale);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function sort(Builder $query, string $sort, ?string $locale = null): Builder
    {
        $locale ??= App::getLocale();

        return match ($sort) {
            'newest' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            'price_asc' => $query->orderByRaw('products.price ASC')->orderBy('products.id'),
            'price_desc' => $query->orderByRaw('products.price DESC')->orderBy('products.id'),
            'name' => $query
                // Alphabetical order *as the shopper reads it*, i.e. in their language.
                ->orderBy(
                    ProductTranslation::query()
                        ->select('name')
                        ->whereColumn('product_translations.product_id', 'products.id')
                        ->where('product_translations.locale', $locale)
                        ->limit(1)
                )
                ->orderBy('products.id'),
            // "Featured" surfaces curated picks first, then best sellers.
            default => $query
                ->orderByDesc('products.is_featured')
                ->orderByDesc('products.sales_count')
                ->orderByDesc('products.rating')
                ->orderByDesc('products.id'),
        };
    }

    /**
     * The shop's curated shelves.
     *
     * The homepage used to carry three of these — best sellers, new arrivals
     * and offers — and it does not any more: the storefront shows the whole
     * catalogue under its hero, so the payload no longer spends three
     * eager-loaded queries on answers nobody reads. The rules behind them are
     * the shop's merchandising vocabulary and they stay here, tested
     * directly in `ProductShelvesTest`.
     *
     * @return Collection<int, Product>
     */
    public function featured(int $limit = 8, ?string $locale = null): Collection
    {
        return $this->shelf($locale, fn (Builder $q) => $q->featured(), $limit);
    }

    /**
     * Newest first, by creation date.
     *
     * This used to be a filter over a hand-ticked `is_new` column OR'd with a
     * 45-day window, which meant the two halves disagreed and the flag was the
     * weaker one. Ordering by `created_at` alone is the same intention, needs no
     * upkeep, and cannot go stale.
     *
     * `id` breaks ties because `created_at` has one-second granularity, and SQL
     * promises no order among equal keys. Both SQLite and InnoDB happen to
     * already return ties id-descending, because a secondary index carries the
     * row id and the scan runs backwards — so this clause is belt-and-braces
     * against that coincidence rather than a fix for an observed bug. Kept
     * because "the 4 newest" should not depend on a storage engine's internals,
     * and ProductShelvesTest pins the contract even though it cannot prove this
     * clause is load-bearing.
     *
     * @return Collection<int, Product>
     */
    public function newArrivals(int $limit = 8, ?string $locale = null): Collection
    {
        return $this->shelf(
            $locale,
            fn (Builder $q) => $q->orderByDesc('products.created_at')->orderByDesc('products.id'),
            $limit
        );
    }

    /**
     * The shelf of what has actually sold, with an honest answer when nothing
     * has.
     *
     * @return Collection<int, Product>
     */
    public function bestSellers(int $limit = 8, ?string $locale = null): Collection
    {
        return $this->shelf(
            $locale,
            function (Builder $q) {
                // With no sales anywhere the shelf would be an arbitrary four,
                // and worse, a *newest* four, because that is the tie-break the
                // newArrivals shelf wants and it is the opposite of what this
                // heading claims. Showing the longest-standing products instead
                // is honest: nothing has proved popular yet.
                //
                // Asked once per request. `sales_count` is unindexed, so this is
                // a scan of the products table — fine at catalogue scale, and
                // still cheaper than the conditional SQL that would avoid it.
                $maxSales = (int) Product::query()->active()->inStock()->max('sales_count');

                if ($maxSales <= 0) {
                    return $q->orderBy('products.created_at')->orderBy('products.id');
                }

                // `id` tie-break for the same reason as newArrivals: products with
                // equal sales would otherwise be ordered by whatever the storage
                // engine felt like.
                return $q->orderByDesc('sales_count')->orderByDesc('products.id');
            },
            $limit
        );
    }

    /**
     * The discounted shelf, best selling first.
     *
     * @return Collection<int, Product>
     */
    public function onOffer(int $limit = 8, ?string $locale = null): Collection
    {
        return $this->shelf(
            $locale,
            fn (Builder $q) => $q->discounted()->orderByDesc('sales_count')->orderByDesc('products.id'),
            $limit
        );
    }

    /**
     * @param  callable(Builder): Builder  $constrain
     * @return Collection<int, Product>
     */
    private function shelf(?string $locale, callable $constrain, int $limit): Collection
    {
        return $constrain(
            Product::query()->active()->inStock()->with([
                'translations', 'images', 'brand.translations', 'categories.translations',
            ])
        )->take($limit)->get();
    }

    /**
     * Same brand first, then same category — never the product itself.
     *
     * @return Collection<int, Product>
     */
    public function related(Product $product, int $limit = 4, ?string $locale = null): Collection
    {
        $locale ??= App::getLocale();

        return Product::query()
            ->active()
            ->where('products.id', '!=', $product->id)
            ->with(['translations', 'images', 'brand.translations', 'categories.translations'])
            ->where(function (Builder $q) use ($product) {
                $q->where('brand_id', $product->brand_id);

                $categoryIds = $product->categories()->pluck('categories.id');

                // Any category in common, not just one: the product may sit in
                // several and each of them is a reason to suggest a sibling.
                if ($categoryIds->isNotEmpty()) {
                    $q->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds));
                }
            })
            ->orderByDesc('sales_count')
            ->take($limit)
            ->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brands(bool $onlyWithProducts = false): Collection
    {
        $query = Brand::query()->active()->with('translations')->ordered();

        if ($onlyWithProducts) {
            $query->whereHas('products', fn ($q) => $q->active());
        }

        return $query->get();
    }

    /**
     * The brand list for the homepage carousel, as lean rows rather than models.
     *
     * The tiles are photographs with the brand name on them. `BrandCard` reads
     * `id`, `slug`, `name` and `logo` and nothing else — but this list was going
     * out through `BrandResource`, so every cold homepage load shipped a
     * description, an origin, a canonical URL and three hreflang alternates per
     * brand to render a tile. On a twelve-brand shop that measured 7.9 KB of a
     * 15.3 KB page payload, over half of it, for fields no tile touches.
     *
     * The homepage is not an index the way `/brands` is, so those fields have
     * nowhere to go here. `brandTiles()` also differs from `brands()` in what it
     * omits rather than adds: a brand with no logo is filtered out upstream,
     * because an empty tile is worse than a shorter carousel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brandTiles(string $locale): array
    {
        $chain = $this->localeChain($locale);
        $aliases = $this->translationAliases($chain);

        $rows = $this->joinTranslationChain(
            DB::table('brands'),
            'brands',
            'brand_translations',
            'brand_id',
            $chain,
            $aliases,
        )
            ->join('products', function ($join) {
                // The same `onlyWithProducts` rule as `brands()`: a brand with
                // nothing published has no tile to show.
                //
                // This join matches once per product, not once per brand, so it
                // deliberately does *not* make the result distinct — a brand with
                // four products arrives four times and is collapsed by the
                // `unique('id')` below. A `whereExists` subquery would be the
                // other way to write this; the join is here because the product
                // table is only being used as a yes/no, never read.
                $join->on('products.brand_id', '=', 'brands.id')
                    ->where('products.is_active', true)
                    ->whereNull('products.deleted_at');
            })
            ->where('brands.is_active', true)
            // The storefront used to drop a brand with no logo in the browser, on
            // a truthiness test that caught both `null` and `''`. `whereNotNull`
            // alone catches only the first, and the second would slip through to
            // `url('')` — a tile with a broken image where the brand was
            // supposed to be absent. Both spellings of "no logo" have to go.
            ->where(fn ($query) => $query
                ->whereNotNull('brands.logo')
                ->where('brands.logo', '<>', '')
            )
            ->orderByRaw('brands.position = 0')
            ->orderBy('brands.position')
            ->orderByRaw('case when brands.position = 0 then brands.id else 0 end')
            ->orderBy('brands.name')
            ->orderBy('brands.id')
            ->get([
                'brands.id',
                'brands.slug',
                'brands.logo',
                'brands.name as canonical_name',
                ...$this->translationColumns($aliases, 'name'),
            ]);

        $disk = Storage::disk(config('chamma.disk'));

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'slug' => $row->slug,
            'name' => $this->pick(...[...$this->translationValues($row, $aliases, 'name'), $row->canonical_name]),
            'logo' => $disk->url($row->logo),
        ])->unique('id')->values()->all();
    }

    /**
     * Select clauses for the joined translation aliases, aliased so PHP can read
     * them back off the row.
     *
     * @param  array<int, string>  $aliases
     * @return array<int, string>
     */
    private function translationColumns(array $aliases, string $column): array
    {
        return array_map(fn (string $alias) => "$alias.$column as {$alias}_$column", $aliases);
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(bool $onlyWithProducts = false): Collection
    {
        $query = Category::query()->active()->with('translations')->ordered();

        if ($onlyWithProducts) {
            $query->whereHas('products', fn ($q) => $q->active())
                ->withCount(['products' => fn ($q) => $q->active()]);
        } else {
            $query->withCount(['products' => fn ($q) => $q->active()]);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filled(array $filters, string $key): bool
    {
        $value = $filters[$key] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Accepts either a slug or a numeric id, so the storefront can link to
     * /products?category=perfumes while the admin links to ?category=3.
     */
    private function numeric(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Price bounds + counts used to render the filter sidebar.
     *
     * Locale-independent — prices and counts are the same in every language —
     * so one key serves every listing.
     *
     * @return array{min: float, max: float, count: int, brands: int, categories: int}
     */
    public function bounds(): array
    {
        return Cache::remember(
            self::BOUNDS_CACHE_KEY,
            self::BOUNDS_TTL_SECONDS,
            fn () => $this->computeBounds(),
        );
    }

    /**
     * Drop the cached bounds and the cached listings.
     *
     * Called from the admin writes that can change a price, an active flag or
     * a taxonomy row. Placing an order does not call it: stock moves, but
     * bounds read price and activity, not stock.
     */
    public function flush(): void
    {
        Cache::forget(self::BOUNDS_CACHE_KEY);

        $this->flushListings();
    }

    /**
     * Drop every cached listing.
     *
     * The listings are keyed by their state rather than by a name, so there is
     * no single key to forget — but every dimension of that state is bounded,
     * so the whole key space can be enumerated and dropped.
     *
     * Separate from `flush()` because an order moves stock, and stock is in
     * the listing, while the bounds read price and activity and do not move.
     */
    public function flushListings(): void
    {
        foreach ($this->listingCacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * @return array{min: float, max: float, count: int, brands: int, categories: int}
     */
    private function computeBounds(): array
    {
        $row = Product::query()->active()
            ->selectRaw('MIN(price) as min, MAX(price) as max')
            ->first();

        return [
            'min' => (float) ($row->min ?? config('chamma.catalog.min_price')),
            'max' => (float) ($row->max ?? config('chamma.catalog.max_price')),
            'count' => Product::query()->active()->count(),
            'brands' => Brand::query()->active()->count(),
            'categories' => Category::query()->active()->count(),
        ];
    }
}
