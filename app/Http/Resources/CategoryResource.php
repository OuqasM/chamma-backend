<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use App\Support\StorefrontUrl;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name($locale),
            'description' => $this->description($locale),
            'image' => $this->image
                ? [
                    'url' => Storage::disk(config('chamma.disk'))->url($this->image),
                    'width' => 1200,
                    'height' => 900,
                ]
                : null,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'url' => '/'.$locale.'/categories/'.$this->slug,
            // Absolute for crawlers; `url` stays relative for links.
            'canonical' => StorefrontUrl::to('/'.$locale.'/categories/'.$this->slug),
            'alternates' => $this->alternates(),
            'seo' => [
                'title' => $this->translated('meta_title', $locale) ?: $this->name($locale),
                'description' => $this->translated('meta_description', $locale),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function alternates(): array
    {
        $paths = [];

        foreach (array_keys(config('chamma.locales')) as $locale) {
            $paths[$locale] = StorefrontUrl::to('/'.$locale.'/categories/'.$this->slug);
        }

        return $paths;
    }
}
