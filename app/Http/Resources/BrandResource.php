<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name($locale),
            'tagline' => $this->translated('tagline', $locale),
            'description' => $this->description($locale),
            'origin' => $this->origin,
            'logo' => $this->logo
                ? Storage::disk(config('chamma.disk'))->url($this->logo)
                : null,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'url' => '/'.$locale.'/brands/'.$this->slug,
            'alternates' => $this->alternates(),
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
