<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * Admin view of a brand.
 *
 * Same reasoning as AdminCategoryResource: the storefront resource exposes one
 * language, while the admin form must read and write all of them, and the logo
 * has to round-trip as its stored path rather than a resolved URL.
 */
class AdminBrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            // The canonical, single name — not a locale-dependent one.
            'name' => $this->singleName(),
            'tagline' => $this->translated('tagline', $locale),
            'description' => $this->description($locale),
            'origin' => $this->origin,
            'logo' => $this->logo
                ? Storage::disk(config('chamma.disk'))->url($this->logo)
                : null,
            // The raw column value, for the edit form.
            'logo_path' => $this->logo,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'url' => '/'.$locale.'/brands/'.$this->slug,
            'alternates' => $this->alternates(),

            'translations' => $this->whenLoaded('translations', fn () => $this->translations
                ->map(fn ($t) => [
                    'locale' => $t->locale,
                    'tagline' => $t->tagline,
                    'description' => $t->description,
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
            $paths[$locale] = '/'.$locale.'/brands/'.$this->slug;
        }

        return $paths;
    }
}
