<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * Admin view of a category.
 *
 * Mirrors the storefront CategoryResource and adds the two things only the
 * admin form can work with: every translation (the public resource exposes one
 * language, but saving requires all of them, so editing from it would blank the
 * others) and the stored path behind each image, which is what the API expects
 * back on write — the public resource only ever sends the resolved URL.
 */
class AdminCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            // The canonical, single name — not a locale-dependent one.
            'name' => $this->singleName(),
            'description' => $this->description($locale),
            'image' => $this->image
                ? [
                    'url' => Storage::disk(config('chamma.disk'))->url($this->image),
                    'path' => $this->image,
                    'width' => 1200,
                    'height' => 900,
                ]
                : null,
            // The raw column value, for the edit form.
            'image_path' => $this->image,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'url' => '/'.$locale.'/categories/'.$this->slug,
            'alternates' => $this->alternates(),
            'seo' => [
                'title' => $this->translated('meta_title', $locale) ?: $this->name($locale),
                'description' => $this->translated('meta_description', $locale),
            ],

            'translations' => $this->whenLoaded('translations', fn () => $this->translations
                ->map(fn ($t) => [
                    'locale' => $t->locale,
                    'description' => $t->description,
                    'meta_title' => $t->meta_title,
                    'meta_description' => $t->meta_description,
                ])->values()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function alternates(): array
    {
        $paths = [];

        foreach (array_keys(config('chamma.locales')) as $locale) {
            $paths[$locale] = '/'.$locale.'/categories/'.$this->slug;
        }

        return $paths;
    }
}
