<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\HasSingleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Services\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use HasSingleName;

    public function __construct(private readonly ImageLibrary $images) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->with(['translations', 'images', 'brand', 'category'])
            ->orderByDesc('updated_at');

        if ($search = trim((string) $request->query('search'))) {
            $query->search($search);
        }

        if ($request->filled('brand')) {
            $query->whereHas('brand', fn ($q) => $q->where('slug', $request->query('brand')));
        }

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->query('category')));
        }

        if ($request->has('active')) {
            $query->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return AdminProductResource::collection(
            $query->paginate((int) config('chamma.catalog.admin_per_page'))->withQueryString()
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['translations', 'images', 'brand', 'category']);

        return response()->json(['product' => new AdminProductResource($product)]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = Product::create($this->attributes($request));

            $this->syncTranslations($product, $request);
            $this->syncImages($product, $request, isNew: true);

            return $product;
        });

        // Refresh so column defaults that were not submitted (is_active and
        // friends) are reflected in the response.
        $product->refresh();

        return response()->json([
            'product' => new AdminProductResource($product->load(['translations', 'images', 'brand', 'category'])),
        ], 201);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        DB::transaction(function () use ($request, $product) {
            $product->update($this->attributes($request));

            $this->syncTranslations($product, $request);
            $this->syncImages($product, $request);
        });

        return response()->json([
            'product' => new AdminProductResource($product->fresh(['translations', 'images', 'brand', 'category'])),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Soft delete: order history keeps referring to the product.
        $product->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function restore(int $product): JsonResponse
    {
        $model = Product::withTrashed()->findOrFail($product);
        $model->restore();

        return response()->json(['product' => new AdminProductResource($model->load(['translations', 'images', 'brand', 'category']))]);
    }

    public function toggle(Product $product): JsonResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        return response()->json([
            'product' => new AdminProductResource($product->fresh(['translations', 'images', 'brand', 'category'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductRequest $request): array
    {
        $data = $request->safe()->only([
            'brand_id', 'category_id', 'sku', 'slug', 'price', 'cost_price',
            'compare_at_price', 'stock', 'size', 'gender', 'is_active',
            'is_featured', 'is_new',
        ]);

        foreach (['is_active', 'is_featured', 'is_new'] as $flag) {
            // Coerce submitted checkboxes, but let the column default apply when
            // the admin says nothing, so a new product is not silently hidden.
            if ($request->exists($flag) && $request->input($flag) !== null) {
                $data[$flag] = $request->boolean($flag);
            } else {
                unset($data[$flag]);
            }
        }

        // `slug` is nullable, so it is simply absent from the validated array
        // when the admin leaves it blank. The URL then follows the single name,
        // which is what a shopper would type, and only falls back to the SKU.
        $data['slug'] = $this->uniqueSlug(
            $data['slug'] ?? $request->validated()['name'] ?? $data['sku'],
            $request->route('product')?->id,
        );

        // These columns are NOT NULL with a default, and the request rules accept
        // null so a blank form field validates — write the column default rather
        // than letting a NULL reach the database.
        foreach (['gender' => 'unisex', 'rating' => 0, 'rating_count' => 0] as $field => $default) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = $default;
            }
        }

        return $data;
    }

    /**
     * Slugs stay ASCII and stable; localised slugs live on the translations.
     */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * One name and one set of copy, written across the translation rows.
     *
     * Rows that already exist keep their own localized slug and any translated
     * copy; only the fallback row receives what the admin typed. A brand-new
     * product gets a row per language so every locale resolves, each with a slug
     * derived from the name.
     */
    private function syncTranslations(Product $product, ProductRequest $request): void
    {
        $name = $request->validated()['name'];

        $this->syncCanonicalName(
            $product,
            $name,
            fn (string $locale) => [
                'slug' => $this->uniqueTranslationSlug(null, $name, $locale, $product->id),
                'description' => '',
            ],
        );

        $copy = [];
        foreach (['description', 'short_description', 'meta_title', 'meta_description'] as $field) {
            if ($request->exists($field)) {
                // The column is NOT NULL, so a cleared description is stored empty.
                $copy[$field] = $field === 'description' ? ($request->input($field) ?? '') : $request->input($field);
            }
        }

        $this->syncCanonicalCopy($product, $copy);
    }

    private function uniqueTranslationSlug(?string $slug, string $name, string $locale, int $productId): string
    {
        $base = Str::slug($slug ?: $name) ?: 'produit';
        $candidate = $base;
        $suffix = 2;

        while (ProductTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->where('product_id', '!=', $productId)
            ->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    /**
     * Images: admin uploads (already stored paths) and/or generated artwork.
     */
    private function syncImages(Product $product, ProductRequest $request, bool $isNew = false): void
    {
        $paths = array_values(array_filter($request->input('images', []) ?? []));

        if ($isNew && $paths === [] && $request->filled('artwork.shape')) {
            $paths = $this->images->productGallery($product->slug, array_merge(
                ['label' => $product->brand?->name ?? config('chamma.name')],
                $request->input('artwork')
            ));
        }

        foreach ($request->input('remove_images', []) as $imageId) {
            $image = $product->images()->whereKey($imageId)->first();

            if ($image) {
                $this->images->delete($image->path);
                $image->delete();
            }
        }

        $existing = $product->images()->pluck('path')->all();

        foreach (array_diff($paths, $existing) as $index => $path) {
            $product->images()->create([
                'path' => $path,
                'alt' => $product->name(),
                'is_primary' => $index === 0 && ! $product->images()->exists(),
                'position' => $index,
            ]);
        }
    }
}
