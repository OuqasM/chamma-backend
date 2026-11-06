<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
