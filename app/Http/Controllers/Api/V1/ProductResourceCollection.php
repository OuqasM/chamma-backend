<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Services\CatalogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every catalogue listing answers the same envelope: `data` (products) plus
 * `meta.filters` (the normalised query) and `meta.bounds` (sidebar data), so
 * the shop UI can render one code path for /products, /categories/:slug,
 * /brands/:slug and /offers.
 */
final class ProductResourceCollection
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $extra   e.g. ['category' => new CategoryResource($category)]
     * @return AnonymousResourceCollection
     */
    public static function make(LengthAwarePaginator $products, array $filters = [], array $extra = []): AnonymousResourceCollection
    {
        return ProductResource::collection($products)->additional([
            'meta' => array_merge([
                'filters' => $filters,
                'bounds' => app(CatalogService::class)->bounds(),
            ], $extra),
        ]);
    }
}
