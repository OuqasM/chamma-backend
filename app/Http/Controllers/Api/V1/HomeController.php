<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Services\CatalogService;
use App\Services\ImageLibrary;
use App\Services\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Everything the homepage needs in a single request: one round trip keeps the
 * first paint fast.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ShippingService $shipping,
        private readonly ImageLibrary $images,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $locale = App::getLocale();

        $hero = $this->images->heroArtwork();

        return response()->json([
            'locale' => $locale,
            'hero' => [
                'image' => asset_path($hero),
                'eyebrow' => __('api.store.tagline', [], $locale),
            ],
            'brands' => $this->catalog->brandTiles($locale),
            'categories' => CategoryResource::collection(
                $this->catalog->categories(onlyWithProducts: true)
            ),
            // Four per shelf: each of these renders as a carousel, so a longer
            // list means the shopper never sees the end of it without scrolling.
            'best_sellers' => ProductResource::collection($this->catalog->bestSellers(4, $locale)),
            'new_arrivals' => ProductResource::collection($this->catalog->newArrivals(4, $locale)),
            'offers' => ProductResource::collection($this->catalog->onOffer(4, $locale)),
            'promises' => [
                'nationwide' => __('api.store.nationwide', [], $locale),
                'cod' => __('api.store.cod', [], $locale),
                'estimate' => $this->shipping->estimate($locale),
                'free_threshold' => $this->shipping->freeThreshold(),
            ],
        ]);
    }
}
