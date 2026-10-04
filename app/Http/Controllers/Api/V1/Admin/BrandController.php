<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\HasSingleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandRequest;

use App\Models\Brand;
use App\Services\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    use HasSingleName;

    public function __construct(private readonly ImageLibrary $images) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminBrandResource::collection(
            Brand::query()->with('translations')->withCount('products')->ordered()->get()
        );
    }

    public function store(BrandRequest $request): JsonResponse
    {
        $brand = DB::transaction(function () use ($request) {
            $data = $request->safe()->only(['name', 'slug', 'origin', 'logo', 'position', 'is_active']);
            // Coerce a submitted checkbox, but let the column default apply
            // when the admin says nothing, so nothing is silently hidden.
            if ($request->exists('is_active') && $request->input('is_active') !== null) {
                $data['is_active'] = $request->boolean('is_active');
            } else {
                unset($data['is_active']);
            }
            // `slug` is nullable, so it may be absent from the validated array.
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);

            $brand = Brand::create($data);

            $this->syncTranslations($brand, $request);

            return $brand;
        });

        // Refresh so column defaults that were not submitted are reflected.
        $brand->refresh();

        return response()->json(['brand' => new AdminBrandResource($brand->load('translations'))], 201);
    }

    public function update(BrandRequest $request, Brand $brand): JsonResponse
    {
        DB::transaction(function () use ($request, $brand) {
            $data = $request->safe()->only(['name', 'slug', 'origin', 'logo', 'position', 'is_active']);
            // Coerce a submitted checkbox, but let the column default apply
            // when the admin says nothing, so nothing is silently hidden.
            if ($request->exists('is_active') && $request->input('is_active') !== null) {
                $data['is_active'] = $request->boolean('is_active');
            } else {
                unset($data['is_active']);
            }

            if (! empty($data['slug'])) {
                $data['slug'] = $this->uniqueSlug($data['slug'], $brand->id);
            } else {
                // `slug` is nullable, so a client is allowed to send null to mean
                // "leave it as it is" — but it must not reach the NOT NULL
                // column, so drop it from the update entirely.
                unset($data['slug']);
            }

            $brand->update($data);

            $this->syncTranslations($brand, $request);
        });

        return response()->json(['brand' => new AdminBrandResource($brand->fresh('translations'))]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->products()->exists()) {
            return response()->json([
                'message' => __('api.errors.brand_in_use'),
            ], 422);
        }

        $this->images->delete($brand->logo);
        $brand->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * One name and one set of copy, written across the translation rows.
     *
     * Locales that were translated before keep their own copy untouched; only the
     * fallback row receives what the admin typed.
     */
    private function syncTranslations(Brand $brand, BrandRequest $request): void
    {
        $name = $request->validated()['name'];

        $this->syncCanonicalName($brand, $name);

        $copy = [];
        foreach (['tagline', 'description'] as $field) {
            if ($request->exists($field)) {
                $copy[$field] = $request->input($field);
            }
        }

        $this->syncCanonicalCopy($brand, $copy);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'brand';
        $slug = $base;
        $suffix = 2;

        while (Brand::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
