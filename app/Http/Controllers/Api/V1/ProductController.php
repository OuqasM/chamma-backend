<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = self::filters($request);

        $products = $this->catalog->paginate(
            $filters,
            (int) $request->integer('per_page', (int) config('chamma.catalog.per_page')),
        );

        return ProductResourceCollection::make($products, $filters, [
            'sorts' => array_keys(CatalogService::SORTS),
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale = App::getLocale();

        $product = Product::query()
            ->active()
            ->whereSlug($slug, $locale)
            ->with([
                'translations',
                'images',
                'brand.translations',
                'category.translations',
            ])
            ->firstOrFail();

        $related = $this->catalog->related($product, (int) config('chamma.catalog.related_limit'), $locale);

        return response()->json([
            'product' => new ProductResource($product),
            'related' => ProductResource::collection($related),
        ]);
    }

    /**
     * Type-ahead search across name, brand and category.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->input('q', ''));

        $products = $this->catalog->query(['search' => $term], App::getLocale())
            ->take((int) $request->integer('limit', 8))
            ->get();

        return ProductResource::collection($products);
    }

    /**
     * Whitelisted filter set — nothing from the request reaches the query
     * without being listed here.
     *
     * @return array<string, mixed>
     */
    public static function filters(Request $request): array
    {
        $filters = [];

        foreach (['search', 'brand', 'category', 'sort'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = Str::trim($value);
            }
        }

        foreach (['min_price', 'max_price'] as $key) {
            $value = $request->query($key);

            if ($value !== null && is_numeric($value)) {
                $filters[$key] = (float) $value;
            }
        }

        foreach (['on_sale', 'in_stock', 'new', 'featured'] as $flag) {
            $value = $request->query($flag);

            if ($value !== null) {
                $filters[$flag] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // The storefront, the /new redirect and the documented API contract all
        // send `is_new`, while CatalogService reads `new`. Accept either and
        // normalise, so the filter is not silently dropped.
        if (! array_key_exists('new', $filters)) {
            $value = $request->query('is_new');

            if ($value !== null) {
                $filters['new'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($filters['sort']) && ! array_key_exists($filters['sort'], CatalogService::SORTS)) {
            $filters['sort'] = 'featured';
        }

        return $filters;
    }
}
