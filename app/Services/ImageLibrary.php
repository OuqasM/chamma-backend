<?php

namespace App\Services;

use App\Support\Artwork\ArtworkGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Owns the storefront media library.
 *
 * Everything lives under public/images so the API container serves it
 * directly (no storage symlink needed inside Docker).
 */
class ImageLibrary
{
    public function __construct(private readonly ArtworkGenerator $artwork) {}

    /**
     * Generate a product shot and register it as one gallery image.
     *
     * @param  array{shape?:string,tone?:string,size?:string,variant?:int}  $options
     */
    public function productShot(string $slug, array $options = []): string
    {
        $name = $slug.'-'.($options['variant'] ?? 0).'.svg';
        $path = 'products/'.$name;

        $this->write($path, fn () => $this->artwork->product($options));

        return $path;
    }

    /**
     * @return list<string>
     */
    public function productGallery(string $slug, array $product): array
    {
        $shape = $product['shape'] ?? 'flacon';
        $tone = $product['tone'] ?? 'ivory';
        $size = $product['size'] ?? '';
        $label = $product['label'] ?? '';

        $shots = [
            ['shape' => $shape, 'tone' => $tone, 'size' => $size, 'label' => $label, 'variant' => 0],
            ['shape' => $shape, 'tone' => $tone === 'ivory' ? 'blush' : 'ivory', 'size' => $size, 'label' => $label, 'variant' => 1],
            ['shape' => 'carton', 'tone' => $tone, 'size' => $size, 'label' => $label, 'variant' => 2],
        ];

        return array_map(
            fn (array $shot): string => $this->productShot($slug, $shot),
            $shots
        );
    }

    public function collectionArtwork(string $slug, array $items): string
    {
        $path = 'categories/'.$slug.'.svg';

        $this->write($path, fn () => $this->artwork->collection($items));

        return $path;
    }

    public function brandLogo(string $slug, string $name, string $tone = 'ivory'): string
    {
        $path = 'brands/'.$slug.'.svg';

        $this->write($path, fn () => $this->artwork->brandLogo($name, $tone));

        return $path;
    }

    public function heroArtwork(): string
    {
        $path = 'editorial/hero.svg';

        $this->write($path, fn () => $this->artwork->hero(['size' => 1400, 'tone' => 'espresso']));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function bannerArtwork(string $name, array $options = []): string
    {
        $path = 'editorial/'.Str::slug($name).'.svg';

        $this->write($path, fn () => $this->artwork->banner($options));

        return $path;
    }

    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk(config('chamma.disk'))->url($path);
    }

    /**
     * Remove a stored file, and the card-size copy written beside it.
     *
     * The upload endpoint writes every raster twice: `uploads/name` and
     * `uploads/thumbs/name`. Deleting only the first leaves the thumbnail on
     * the disk with no row pointing at it — a file the panel can no longer
     * show and the admin can no longer delete. Every caller that removes an
     * image goes through here, so the pair cannot drift apart.
     *
     * Generated artwork (`categories/…`, `brands/…`) and external URLs have no
     * thumbnail; the second delete is simply a no-op for them.
     */
    public function delete(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }

        $disk = Storage::disk(config('chamma.disk'));
        $disk->delete($path);

        if (str_starts_with($path, 'uploads/') && ! str_starts_with($path, 'uploads/thumbs/')) {
            $disk->delete('uploads/thumbs/'.pathinfo($path, PATHINFO_BASENAME));
        }
    }

    /**
     * @param  callable(): string  $factory
     */
    private function write(string $path, callable $factory): void
    {
        $disk = Storage::disk(config('chamma.disk'));

        if ($disk->exists($path)) {
            return;
        }

        $disk->put($path, $factory());
    }
}
