<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The homepage payload, assembled once and cached per locale.
 *
 * The homepage is the one page every visitor lands on, and its payload is the
 * most expensive read in the shop: the brand tiles join and the delivery
 * promises. None of that changes between two shoppers arriving a minute
 * apart, so the assembled answer is cached and every write that can change it
 * flushes it.
 *
 * The writes that matter are the admin's product, brand and category edits,
 * and each of those paths calls `flush()`. The TTL is the backstop for a write
 * path that is ever missed, not the primary mechanism — same contract as
 * `NavigationService`.
 *
 * **What is not in here is as deliberate as what is.** The payload used to
 * carry three product shelves — best sellers, new arrivals and offers — plus
 * the category list. The storefront replaced the shelves with the whole
 * catalogue under the hero, so it reads none of them: what the hero needs is
 * the brand tiles, the delivery promises and the hero artwork, and the
 * catalogue comes from the listing endpoint, which is cached in its own right
 * (`CatalogService`). Carrying them here cost three eager-loaded queries on
 * every cache miss, a payload nobody reads on every request, and a cache
 * flush on every order — a sale moved the stock the shelves showed, and with
 * the shelves gone it moves nothing here.
 *
 * The rules behind the shelves are the shop's merchandising vocabulary and
 * they stay in `CatalogService`, where they are tested directly.
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
     * A price change is a price change in every language: the tiles read the
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
