<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Upload optimization: rasters are downscaled before they are stored.
 *
 * Everything the admin panel shows comes from the same public/images bucket
 * the storefront serves, so a raw phone-camera original multiplied across
 * product cards is what a shopper waits for. These tests pin the contract the
 * optimizer introduced: oversized images shrink to the configured ceiling,
 * already-small images are left alone, SVG passes through byte-identical,
 * and the stored name/extension stay what the panel expects.
 *
 * The dimension cap alone could not fix every page-load, though: a truecolor
 * PNG photograph is an order of magnitude heavier than the same pixels as
 * JPEG however it is resized, so big opaque PNGs are re-encoded as JPEG (the
 * alpha scan decides), the stored name follows the new codec, and every
 * upload gains a card-size thumbnail the grids can use instead of the
 * ceiling file.
 */
class UploadOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('chamma.disk'));
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_oversized_jpeg_is_downscaled_and_reencoded(): void
    {
        // A "phone camera" original: 3264 wide, solid grey so the assertion is
        // about pixels, not codec noise.
        $source = UploadedFile::fake()->image('shot.jpg', 3264, 2448);

        $response = $this->postJson('/api/admin/uploads', ['image' => $source]);

        $response->assertCreated();

        $stored = Storage::disk(config('chamma.disk'))->get($response->json('path'));
        $size = getimagesizefromstring($stored);

        $this->assertNotNull($size);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThanOrEqual(config('chamma.uploads.max_dimension'), $size[0]);
        $this->assertLessThanOrEqual(config('chamma.uploads.max_dimension'), $size[1]);
    }

    public function test_small_images_pass_through_without_upscaling(): void
    {
        $logo = UploadedFile::fake()->image('logo.png', 420, 280);

        $response = $this->postJson('/api/admin/uploads', ['image' => $logo]);

        $response->assertCreated();

        $stored = Storage::disk(config('chamma.disk'))->get($response->json('path'));
        $size = getimagesizefromstring($stored);

        $this->assertNotNull($size);
        $this->assertSame(420, $size[0]);
        $this->assertSame(280, $size[1]);
        // Same format, so the extension the panel received still matches.
        $this->assertSame('image/png', $size['mime']);
        // And a logo needs no card thumbnail: it is already smaller than one.
        $this->assertNull($response->json('thumb'));
    }

    public function test_svg_is_stored_verbatim(): void
    {
        $source = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        $response = $this->postJson('/api/admin/uploads', [
            'image' => UploadedFile::fake()->createWithContent('mark.svg', $source),
        ]);

        $response->assertCreated();

        $stored = Storage::disk(config('chamma.disk'))->get($response->json('path'));

        $this->assertSame($source, $stored);
        // No raster, no thumbnail.
        $this->assertNull($response->json('thumb'));
    }

    public function test_stored_name_stays_slugged_and_sharded(): void
    {
        $response = $this->postJson('/api/admin/uploads', [
            'image' => UploadedFile::fake()->image('Expensive Portrait.jpg', 2400, 1600),
        ]);

        $response->assertCreated();

        $path = $response->json('path');

        $this->assertStringStartsWith('uploads/expensive-portrait-', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(1, preg_match('/-[0-9a-f]{8}\.jpg$/', $path));
    }

    public function test_large_opaque_png_is_reencoded_as_jpeg_and_renamed(): void
    {
        // The threshold is lowered here so the synthetic PNG does not have to
        // weigh 200 KB for the conversion branch to run.
        config(['chamma.uploads.png_jpeg_min_bytes' => 1024]);

        $bytes = $this->photoPngBytes(1600, 1200);
        $this->assertGreaterThan(1024, strlen($bytes));

        $response = $this->postJson('/api/admin/uploads', [
            'image' => UploadedFile::fake()->createWithContent('chamma-shot.png', $bytes),
        ]);

        $response->assertCreated();

        $path = $response->json('path');

        // The codec changed, so the stored name had to follow the bytes: a
        // .png suffix over JPEG pixels would serve the wrong Content-Type.
        $this->assertStringEndsWith('.jpg', $path);

        $stored = Storage::disk(config('chamma.disk'))->get($path);
        $size = getimagesizefromstring($stored);

        $this->assertNotNull($size);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThan(strlen($bytes), strlen($stored), 'the JPEG should weigh far less than the PNG');

        $this->assertFalse($this->anyStored(fn (string $file): bool => str_ends_with($file, '.png')));

        // The thumbnail was written from the converted pixels and carries the
        // same name under uploads/thumbs/.
        $thumb = $response->json('thumb');
        $this->assertNotNull($thumb);
        $this->assertSame('uploads/thumbs/'.pathinfo($path, PATHINFO_BASENAME), $thumb);

        $thumbSize = getimagesizefromstring(Storage::disk(config('chamma.disk'))->get($thumb));
        $this->assertNotNull($thumbSize);
        $this->assertSame('image/jpeg', $thumbSize['mime']);
        $this->assertLessThanOrEqual(config('chamma.uploads.thumb_dimension'), max($thumbSize[0], $thumbSize[1]));
        $this->assertStringEndsWith($thumb, $response->json('thumb_url'));
    }

    public function test_large_transparent_png_keeps_its_png(): void
    {
        config(['chamma.uploads.png_jpeg_min_bytes' => 1024]);

        $bytes = $this->photoPngBytes(1600, 1200, transparentBand: true);

        $response = $this->postJson('/api/admin/uploads', [
            'image' => UploadedFile::fake()->createWithContent('mark.png', $bytes),
        ]);

        $response->assertCreated();

        // Alpha exists to preserve, so the codec stays: a JPEG has no alpha
        // and would have flattened the transparency into black.
        $path = $response->json('path');
        $this->assertStringEndsWith('.png', $path);

        $size = getimagesizefromstring(Storage::disk(config('chamma.disk'))->get($path));
        $this->assertNotNull($size);
        $this->assertSame('image/png', $size['mime']);
        // Untouched inside the ceiling — the original bytes ship as they were.
        $this->assertSame(1600, $size[0]);
        $this->assertSame(1200, $size[1]);
    }

    public function test_upload_writes_a_card_thumbnail_alongside(): void
    {
        $response = $this->postJson('/api/admin/uploads', [
            'image' => UploadedFile::fake()->image('photo.jpg', 1600, 2000),
        ]);

        $response->assertCreated();

        $thumb = $response->json('thumb');
        $this->assertNotNull($thumb);
        $this->assertStringStartsWith('uploads/thumbs/', $thumb);
        $this->assertStringEndsWith($thumb, (string) $response->json('thumb_url'));

        $size = getimagesizefromstring(Storage::disk(config('chamma.disk'))->get($thumb));
        $this->assertNotNull($size);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThanOrEqual(config('chamma.uploads.thumb_dimension'), max($size[0], $size[1]));
    }

    public function test_product_payload_exposes_the_thumbnail_when_it_exists(): void
    {
        $with = Product::create(['slug' => 'oud-royale', 'sku' => 'OUD-ROYALE', 'price' => 890, 'stock' => 5]);
        $with->translations()->create(['locale' => 'fr', 'slug' => 'oud-royale', 'name' => 'Oud Royale', 'description' => 'Un parfum boisé.']);
        ProductImage::create(['product_id' => $with->id, 'path' => 'uploads/oud.jpg', 'is_primary' => true]);

        $without = Product::create(['slug' => 'ambre-nuit', 'sku' => 'AMBRE-NUIT', 'price' => 540, 'stock' => 3]);
        $without->translations()->create(['locale' => 'fr', 'slug' => 'ambre-nuit', 'name' => 'Ambre Nuit', 'description' => 'Un ambre chaud.']);
        ProductImage::create(['product_id' => $without->id, 'path' => 'uploads/ambre.jpg', 'is_primary' => true]);

        $disk = Storage::disk(config('chamma.disk'));
        $disk->put('uploads/oud.jpg', 'pixels');
        $disk->put('uploads/thumbs/oud.jpg', 'pixels');
        // The second product's upload predates the thumbs — no file, no claim.
        $disk->put('uploads/ambre.jpg', 'pixels');

        $response = $this->getJson('/api/fr/products?per_page=12')->assertOk();

        // Listed in whatever order the catalogue sorts, so the two products
        // are matched by slug rather than assumed into positions.
        $images = collect($response->json('data'))->pluck('image', 'slug');

        $this->assertStringContainsString('uploads/thumbs/oud.jpg', (string) $images->get('oud-royale')['thumb']);
        $this->assertStringContainsString('uploads/oud.jpg', (string) $images->get('oud-royale')['url']);

        $this->assertArrayNotHasKey('thumb', $images->get('ambre-nuit'));
    }

    /**
     * A photograph-like PNG as bytes: a smooth gradient plus grain, i.e. the
     * continuous-tone data a real product shot is. Flat-colour art is the one
     * case where PNG legitimately beats JPEG — and the optimizer must keep
     * those as they are — so the fixture has to look like what the conversion
     * exists for.
     */
    private function photoPngBytes(int $width, int $height, bool $transparentBand = false): string
    {
        $image = $this->photoCanvas($width, $height);

        if ($transparentBand) {
            imagefilledrectangle($image, 0, 0, $width - 1, 99, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * The canvas under every photo-like fixture: a diagonal gradient plus
     * enough speckle that neither codec can run-length the rows away.
     */
    private function photoCanvas(int $width, int $height)
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($y = 0; $y < $height; $y++) {
            imageline($image, 0, $y, $width - 1, $y, imagecolorallocate(
                $image,
                (int) ($y / $height * 255),
                128,
                (int) (255 - $y / $height * 255),
            ));
        }

        for ($i = 0; $i < 60000; $i++) {
            $x = random_int(0, $width - 1);
            $y = random_int(0, $height - 1);
            $color = imagecolorat($image, $x, $y);

            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                max(0, min(255, ($color & 0xFF) + random_int(-30, 30))),
                max(0, min(255, (($color >> 8) & 0xFF) + random_int(-30, 30))),
                max(0, min(255, (($color >> 16) & 0xFF) + random_int(-30, 30))),
            ));
        }

        return $image;
    }

    private function anyStored(callable $filter): bool
    {
        return Collection::make(Storage::disk(config('chamma.disk'))->allFiles())->contains($filter);
    }
}
