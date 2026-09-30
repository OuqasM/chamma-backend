<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'alt', 'is_primary', 'position'];

    protected $casts = [
        'is_primary' => 'boolean',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        return Storage::disk(config('chamma.disk', 'storefront'))->url($this->path);
    }

    /**
     * Real pixel dimensions, read once per file per process.
     *
     * The resource used to state 1000x1250 for every image regardless of the
     * file, which is a claim about the asset that is simply false. Nothing
     * consumed it, so it was harmless; structured data would have made it a
     * mismatch Google penalises.
     *
     * Returns null rather than a guess when the file cannot be read — an absent
     * dimension is honest, a wrong one is not. Cached on path+mtime so an
     * edited image is re-measured.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(): ?array
    {
        if (str_starts_with($this->path, 'http')) {
            return null;
        }

        $disk = Storage::disk(config('chamma.disk', 'storefront'));
        $key = $this->path;

        $stamp = null;

        try {
            $stamp = $disk->lastModified($key);
        } catch (\Throwable) {
            return null;
        }

        $cacheKey = $key.'@'.$stamp;

        if (isset(self::$dimensionCache[$cacheKey])) {
            return self::$dimensionCache[$cacheKey];
        }

        try {
            $size = @getimagesizefromstring($disk->get($key));
        } catch (\Throwable) {
            $size = false;
        }

        return self::$dimensionCache[$cacheKey] = ($size && $size[0] && $size[1])
            ? ['width' => (int) $size[0], 'height' => (int) $size[1]]
            : null;
    }

    /** @var array<string, array{width: int, height: int}|null> */
    protected static array $dimensionCache = [];
}
