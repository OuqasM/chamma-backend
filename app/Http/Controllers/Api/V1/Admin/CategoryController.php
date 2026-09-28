<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\HasSingleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;

use App\Models\Category;
use App\Services\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    use HasSingleName;

    public function __construct(private readonly ImageLibrary $images) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection(
            Category::query()->with('translations')->withCount('products')->orderBy('position')->get()
        );
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request) {
            $data = $request->safe()->only(['slug', 'image', 'position', 'is_active']);
            // Coerce a submitted checkbox, but let the column default apply
            // when the admin says nothing, so nothing is silently hidden.
            if ($request->exists('is_active') && $request->input('is_active') !== null) {
                $data['is_active'] = $request->boolean('is_active');
            } else {
                unset($data['is_active']);
            }
            // `slug` is nullable, so it may be absent from the validated array.
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $request->validated()['name']);

            $category = Category::create($data);

            $this->syncTranslations($category, $request);
            $this->ensureImage($category);

            return $category;
        });

        // Refresh so column defaults that were not submitted are reflected.
        $category->refresh();

        return response()->json(['category' => new AdminCategoryResource($category->load('translations'))], 201);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        DB::transaction(function () use ($request, $category) {
            $data = $request->safe()->only(['slug', 'image', 'position', 'is_active']);
            // Coerce a submitted checkbox, but let the column default apply
            // when the admin says nothing, so nothing is silently hidden.
            if ($request->exists('is_active') && $request->input('is_active') !== null) {
                $data['is_active'] = $request->boolean('is_active');
            } else {
                unset($data['is_active']);
            }

            if (! empty($data['slug'])) {
                $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
            } else {
                // See BrandController: an omitted or null slug keeps the current
                // one instead of writing NULL into a NOT NULL column.
                unset($data['slug']);
            }

            $category->update($data);

            $this->syncTranslations($category, $request);
            $this->ensureImage($category);
        });

        return response()->json(['category' => new AdminCategoryResource($category->fresh('translations'))]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json(['message' => __('api.errors.category_in_use')], 422);
        }

        $category->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * One name and one set of copy, written across the translation rows.
     *
     * Locales that were translated before keep their own copy untouched; only the
     * fallback row receives what the admin typed.
     */
    private function syncTranslations(Category $category, CategoryRequest $request): void
    {
        $name = $request->validated()['name'];

        $this->syncCanonicalName($category, $name);

        $copy = [];
        foreach (['description', 'meta_title', 'meta_description'] as $field) {
            if ($request->exists($field)) {
                $copy[$field] = $request->input($field);
            }
        }

        $this->syncCanonicalCopy($category, $copy);
    }

    /**
     * Category cards are imagery-first, so a category without a photo gets a
     * generated triptych of bottles in its own palette.
     */
    private function ensureImage(Category $category): void
    {
        if ($category->image) {
            return;
        }

        $tones = [
            'perfumes' => 'amber',
            'body-mist' => 'rose',
            'body-care' => 'blush',
            'shower-bath' => 'jade',
            'gift-sets' => 'espresso',
            'women' => 'plum',
            'men' => 'noir',
        ];

        $tone = $tones[$category->slug] ?? 'ivory';
        $shapes = match ($category->slug) {
            'body-care' => [['shape' => 'jar', 'tone' => $tone, 'size' => '200 ML'], ['shape' => 'tube', 'tone' => 'ivory', 'size' => '100 ML']],
            'body-mist' => [['shape' => 'mist', 'tone' => $tone, 'size' => '250 ML'], ['shape' => 'flacon', 'tone' => 'ivory', 'size' => '50 ML']],
            'gift-sets' => [['shape' => 'carton', 'tone' => $tone], ['shape' => 'flacon', 'tone' => 'amber', 'size' => '100 ML']],
            default => [['shape' => 'flacon', 'tone' => $tone, 'size' => '100 ML'], ['shape' => 'dropper', 'tone' => 'ivory', 'size' => '12 ML']],
        };

        $category->update(['image' => $this->images->collectionArtwork($category->slug, $shapes)]);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'categorie';
        $slug = $base;
        $suffix = 2;

        while (Category::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
