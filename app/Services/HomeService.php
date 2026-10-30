<?php

namespace App\Services;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use Illuminate\Support\Facades\Cache;

/**
 * The homepage payload, assembled once and cached per locale.
 *
 * The homepage is the one page every visitor lands on, and its payload is the
 * most expensive read in the shop: three shelf queries (each with its own
 * eager loads), a `max(sales_count)` scan, the brand tiles join and the
 * category list. None of that changes between two shoppers arriving a minute
 * apart, so the assembled answer is cached and every write that can change it
 * flushes it.
 *
 * The writes that matter are the admin's product, brand and category edits and
 * the placing of an order (stock and sales figures move inside `place()`), and
 * each of those paths calls `flush()`. The TTL is the backstop for a write
 * path that is ever missed, not the primary mechanism — same contract as
 * `NavigationService`.
 *
 * Resources are resolved to plain arrays inside the cached closure: a cached
 * value has to survive serialization for the database cache driver, and an
 * unresolved resource collection does not promise that. The resolved array is
 * byte-identical to what `response()->json()` produced before.
 */
class HomeService
{
    /**
     * Long enough to absorb a browsing session, short enough that a missed
     * flush still self-corrects within a quarter hour.
     */
    private const TTL_SECONDS = 900;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ShippingService $shipping,
        private readonly ImageLibrary $images,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(string $locale): array
    {
        return Cache::remember(
            $this->cacheKey($locale),
            self::TTL_SECONDS,
            fn () => $this->assemble($locale),
        );
    }

    /**
     * Drop the cached homepage for every locale.
     *
     * A price change is a price change in every language: the shelves read the
     * same rows whatever locale they are rendered in, so like the navigation
     * flush this cannot be per-locale and the loop is the honest way to say so.
     */
    public function flush(): void
    {
        foreach (array_keys(config('chamma.locales')) as $locale) {
            Cache::forget($this->cacheKey($locale));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(string $locale): array
    {
        $hero = $this->images->heroArtwork();

        return [
            'locale' => $locale,
            'hero' => [
                'image' => asset_path($hero),
                'eyebrow' => __('api.store.tagline', [], $locale),
            ],
            'brands' => $this->catalog->brandTiles($locale),
            'categories' => CategoryResource::collection(
                $this->catalog->categories(onlyWithProducts: true)
            )->resolve(),
            // Four per shelf: each of these renders as a carousel, so a longer
            // list means the shopper never sees the end of it without scrolling.
            'best_sellers' => ProductResource::collection($this->catalog->bestSellers(4, $locale))->resolve(),
            'new_arrivals' => ProductResource::collection($this->catalog->newArrivals(4, $locale))->resolve(),
            'offers' => ProductResource::collection($this->catalog->onOffer(4, $locale))->resolve(),
            'promises' => [
                'original' => __('api.store.original', [], $locale),
                'nationwide' => __('api.store.nationwide', [], $locale),
                'cod' => __('api.store.cod', [], $locale),
                'estimate' => $this->shipping->estimate($locale),
                'free_threshold' => $this->shipping->freeThreshold(),
            ],
        ];
    }

    private function cacheKey(string $locale): string
    {
        return 'home:'.$locale;
    }
}
