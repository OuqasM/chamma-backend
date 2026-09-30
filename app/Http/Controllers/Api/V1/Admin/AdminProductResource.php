<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;

/**
 * Admin view of a product: everything the catalogue knows, including every
 * translation and the raw values needed by the edit form.
 */
class AdminProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'price' => (float) $this->price,
            // Admin-only: what the shop pays. Deliberately absent from the
            // storefront ProductResource.
            'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'discount_percent' => $this->discount_percent,
            'stock' => (int) $this->stock,
            'size' => $this->size,
            'gender' => $this->gender,
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'is_featured' => (bool) $this->is_featured,
            'rating' => (float) $this->rating,
            'rating_count' => (int) $this->rating_count,
            'sales_count' => (int) $this->sales_count,
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            'brand_id' => $this->brand_id,
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->values()),

            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null),

            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name(App::getLocale()),
                'slug' => $category->slug,
            ])->values()),

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'path' => $image->path,
                'is_primary' => (bool) $image->is_primary,
                'position' => (int) $image->position,
            ])->values()),

            // One name for the whole catalogue (see App\Concerns\HasSingleName);
            // the per-locale rows below carry only the copy that is translated.
            'name' => $this->singleName(),

            'translations' => $this->whenLoaded('translations', fn () => $this->translations
                ->map(fn ($t) => [
                    'locale' => $t->locale,
                    'slug' => $t->slug,
                    'short_description' => $t->short_description,
                    'description' => $t->description,
                    'meta_title' => $t->meta_title,
                    'meta_description' => $t->meta_description,
                ])->values()),

            'preview' => [
                'url' => $this->primaryImage()?->url,
                'name' => $this->singleName(),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
