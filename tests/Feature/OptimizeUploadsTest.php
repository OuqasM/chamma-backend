<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReviewScreenshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The uploads:optimize backfill: what the upload endpoint does now, applied
 * to files that were stored before it existed — plus the one thing the
 * endpoint never has to do, renaming a file when its codec changes and
 * repointing every table column that referenced the old name.
 */
class OptimizeUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('chamma.disk'));
        // Lowered so the synthetic PNG does not have to weigh 200 KB for the
        // conversion branch to run.
        config(['chamma.uploads.png_jpeg_min_bytes' => 1024]);
    }

    public function test_backfill_converts_large_opaque_png_and_repoints_every_reference(): void
    {
        $disk = Storage::disk(config('chamma.disk'));
        $disk->put('uploads/shot.png', $this->photoPngBytes(1600, 1200));

        $category = Category::create(['slug' => 'parfums', 'image' => 'uploads/shot.png']);
        $review = ReviewScreenshot::create(['image' => 'uploads/shot.png', 'position' => 0]);

        $this->artisan('uploads:optimize')->assertSuccessful();

        // The old name is gone, the JPEG is in its place, and both records
        // that pointed at the PNG now point at the JPEG — a category or a
        // review left on a deleted path is exactly the failure this pass
        // exists to prevent.
        $this->assertFalse($disk->exists('uploads/shot.png'));
        $this->assertTrue($disk->exists('uploads/shot.jpg'));

        $size = getimagesizefromstring($disk->get('uploads/shot.jpg'));
        $this->assertNotNull($size);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThanOrEqual(config('chamma.uploads.max_dimension'), max($size[0], $size[1]));

        $this->assertSame('uploads/shot.jpg', $category->fresh()->image);
        $this->assertSame('uploads/shot.jpg', $review->fresh()->image);

        // And the thumbnail follows the new name too.
        $thumb = getimagesizefromstring($disk->get('uploads/thumbs/shot.jpg'));
        $this->assertNotNull($thumb);
        $this->assertLessThanOrEqual(config('chamma.uploads.thumb_dimension'), max($thumb[0], $thumb[1]));
    }

    public function test_backfill_thumbs_uploads_and_leaves_small_files_alone(): void
    {
        $disk = Storage::disk(config('chamma.disk'));

        $small = $this->photoJpegBytes(600, 400);
        $big = $this->photoJpegBytes(1600, 1200);

        $disk->put('uploads/small.jpg', $small);
        $disk->put('uploads/big.jpg', $big);

        $this->artisan('uploads:optimize')->assertSuccessful();

        // Inside the ceiling and already a JPEG: bytes untouched...
        $this->assertSame($small, $disk->get('uploads/small.jpg'));
        // ...and it is small enough for cards anyway, so no thumbnail.
        $this->assertFalse($disk->exists('uploads/thumbs/small.jpg'));

        // The bigger one stays byte-identical (it is within the ceiling) but
        // gains the card thumbnail the grids need.
        $this->assertSame($big, $disk->get('uploads/big.jpg'));
        $this->assertTrue($disk->exists('uploads/thumbs/big.jpg'));
    }

    public function test_backfill_is_a_no_op_on_the_second_run(): void
    {
        $disk = Storage::disk(config('chamma.disk'));
        $disk->put('uploads/shot.png', $this->photoPngBytes(1600, 1200));

        $this->artisan('uploads:optimize')->assertSuccessful();
        $converted = $disk->get('uploads/shot.jpg');

        $this->artisan('uploads:optimize')->assertSuccessful();

        // The rewritten JPEG is inside the ceiling and already the right
        // codec: the second pass finds nothing to do and changes nothing.
        $this->assertSame($converted, $disk->get('uploads/shot.jpg'));
    }

    /**
     * A photograph-like PNG as bytes: a smooth gradient plus grain, i.e. the
     * continuous-tone data a real product shot is. Flat-colour art is the one
     * case where PNG legitimately beats JPEG — and the optimizer must keep
     * those as they are — so the fixture has to look like what the conversion
     * exists for.
     */
    private function photoPngBytes(int $width, int $height): string
    {
        $image = $this->photoCanvas($width, $height);

        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function photoJpegBytes(int $width, int $height): string
    {
        $image = $this->photoCanvas($width, $height);

        ob_start();
        imagejpeg($image, null, 85);
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
}
