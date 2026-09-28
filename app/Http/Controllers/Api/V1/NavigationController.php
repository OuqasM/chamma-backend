<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

/**
 * Bootstrap payload: everything the shell needs on first paint (navigation,
 * footer) in a single request, already translated.
 */
class NavigationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'brands' => BrandResource::collection(
                \App\Models\Brand::query()->active()->with('translations')->ordered()->get()
            ),
            'categories' => CategoryResource::collection(
                \App\Models\Category::query()->active()->with('translations')->ordered()->get()
            ),
        ]);
    }
}
