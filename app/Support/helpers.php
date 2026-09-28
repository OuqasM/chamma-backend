<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('asset_path')) {
    /**
     * Public URL of a storefront media file (products, brands, categories).
     */
    function asset_path(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk(config('chamma.disk'))->url($path);
    }
}
