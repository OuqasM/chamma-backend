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

        $products = $this->catalog->paginate($filters, $this->perPage($request));

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
                'categories.translations',
                'variants',
            ])
            ->firstOrFail();

        $related = $this->catalog->related($product, (int) config('chamma.catalog.related_limit'), $locale);

        return response()->json([
            'product' => new ProductResource($product),
            'related' => ProductResource::collection($related),
        ]);
    }

    /**
     * Search across the catalogue, paginated like the catalogue.
     *
     * It used to answer with a fixed handful of products — `?limit=8` — and no
     * paginator at all, so the search page could show eight rows and nothing
     * else: a shopper searching for "oud" was shown what matched and never
     * saw the rest of it. Search is the catalogue with a term on it, so it
     * takes the same filters, the same page size and the same page row.
     *
     * The term arrives as `q` from the storefront; `search` is the name the
     * filter set uses, and is accepted too.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $filters = self::filters($request);

        $term = trim((string) $request->query('q', $request->query('search', '')));

        if ($term !== '') {
            $filters['search'] = $term;
        }

        $products = $this->catalog->paginate($filters, $this->perPage($request));

        return ProductResourceCollection::make($products, $filters);
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

        foreach (['on_sale', 'in_stock', 'featured'] as $flag) {
            $value = $request->query($flag);

            if ($value !== null) {
                $filters[$flag] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($filters['sort']) && ! array_key_exists($filters['sort'], CatalogService::SORTS)) {
            $filters['sort'] = 'featured';
        }

        return $filters;
    }
}
