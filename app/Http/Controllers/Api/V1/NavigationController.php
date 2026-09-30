<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Models\Brand;
use App\Models\Category;
use App\Services\StoreContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

/**
 * Bootstrap payload: everything the shell needs on first paint (navigation,
 * footer) in a single request, already translated.
 */
class NavigationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $locale = App::getLocale();

        return response()->json([
            'brands' => BrandResource::collection(
                Brand::query()->active()->with('translations')->ordered()->get()
            ),
            'categories' => CategoryResource::collection(
                Category::query()->active()->with('translations')->ordered()->get()
            ),

            // The footer's social links. It rides along here rather than in the
            // non-scoped `/store` bundle because the shell already fetches this
            // once per locale, so the footer costs no extra request — and because
            // `address` is locale-dependent, which `/store` cannot express.
            'contact' => app(StoreContactService::class)->contact($locale),
        ]);
    }
}
