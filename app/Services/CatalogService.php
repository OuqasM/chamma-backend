<?php

namespace App\Services;

use App\Concerns\ReadsTranslatedTaxonomy;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
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
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $locale = $filters['locale'] ?? App::getLocale();

        $query = $this->query($filters, $locale);

        return $query->paginate($perPage ?? (int) config('chamma.catalog.per_page'))
            ->withQueryString();
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
     * and HomeShelvesTest pins the contract even though it cannot prove this
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
                $maxSales = (int) Product::query()->active()->max('sales_count');

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
            Product::query()->active()->with([
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
            ->orderBy('brands.position')
            ->orderBy('brands.name')
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
     * @return array{min: float, max: float, count: int, brands: int, categories: int}
     */
    public function bounds(): array
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
