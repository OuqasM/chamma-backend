<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * Storefront representation of a product: already translated into the
 * requested locale, with the alternates needed for hreflang tags.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();
        $image = $this->primaryImage();

        return [
            'id' => $this->id,
            'slug' => $this->slug($locale),
            'canonical_slug' => $this->slug,
            'name' => $this->name($locale),
            'short_description' => $this->translated('short_description', $locale),
            'description' => $this->description($locale),
            'sku' => $this->sku,
            'size' => $this->size,
            'gender' => $this->gender,

            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'discount_percent' => $this->discount_percent,
            'is_on_sale' => $this->is_on_sale,

            'stock' => $this->stock,
            'in_stock' => $this->in_stock,
            'is_low_stock' => $this->is_low_stock,

            'rating' => (float) $this->rating,
            'rating_count' => (int) $this->rating_count,
            'sales_count' => (int) $this->sales_count,
            'is_new' => (bool) $this->is_new,
            'is_featured' => (bool) $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),

            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'slug' => $this->brand->slug,
                'name' => $this->brand->name($locale),
            ] : null),

            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name($locale),
            ] : null),

            'image' => $image ? [
                'url' => $image->url,
                'alt' => $image->alt ?: $this->name($locale),
                'width' => 1000,
                'height' => 1250,
            ] : null,

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'url' => $i->url,
                'alt' => $i->alt ?: $this->name($locale),
                'is_primary' => (bool) $i->is_primary,
            ])->values()),

            'url' => '/'.$locale.'/products/'.$this->slug($locale),
            'alternates' => $this->alternates(),

            'seo' => [
                'title' => $this->translated('meta_title', $locale) ?: $this->name($locale),
                'description' => $this->translated('meta_description', $locale)
                    ?: \Illuminate\Support\Str::limit(strip_tags((string) $this->description($locale)), 160),
            ],
        ];
    }

    /**
     * Localised URL per language — powers <link rel="alternate" hreflang>.
     *
     * @return array<string, string>
     */
    private function alternates(): array
    {
        $paths = [];

        foreach (array_keys(config('chamma.locales')) as $locale) {
            $paths[$locale] = '/'.$locale.'/products/'.$this->slug($locale);
        }

        return $paths;
    }
}
