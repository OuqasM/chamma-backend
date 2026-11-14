<?php

/**
 * Backfill existing uploads through the image optimizer.
 *
 * Run: php artisan uploads:optimize
 *
 * The optimizer was wired into the upload endpoint after the store already
 * held raw phone-camera originals in public/images, so the storefront kept
 * serving multi-megabyte files until this pass rewrites them. Three things
 * happen per file, all idempotent:
 *
 * - Oversized rasters are downscaled/re-encoded through the same optimizer
 *   the endpoint applies. Files already inside the ceiling are left alone,
 *   which is what makes repeated runs a no-op.
 * - Big opaque PNGs are re-encoded as JPEG — the codec change renames the
 *   file (uploads/foo.png becomes uploads/foo.jpg), the old file is deleted,
 *   and every table column that pointed at the old path is repointed, so an
 *   order snapshot or a review never loses its picture. SVG is skipped.
 * - Every raster under uploads/ gains a card-size thumbnail in
 *   uploads/thumbs/ if it does not have one yet.
 *
 * Corrupt/foreign files are left exactly as they were: the command must never
 * be the thing that empties the media bucket.
 */

namespace App\Console\Commands;

use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class OptimizeUploads extends Command
{
    /** @var list<string> */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Every column that can hold a storefront media path. When the optimizer
     * changes a file's extension these must follow it, or the storefront
     * keeps asking for a file that no longer exists.
     *
     * @var array<string, string>
     */
    private const PATH_COLUMNS = [
        'brands' => 'logo',
        'categories' => 'image',
        'product_images' => 'path',
        'order_items' => 'product_image',
        'review_screenshots' => 'image',
    ];

    private ?ProgressBar $bar = null;

    protected $signature = 'uploads:optimize';

    protected $description = 'Downscale, re-encode and thumbnail every raster image already stored on the storefront disk';

    public function handle(ImageOptimizer $optimizer): int
    {
        $disk = Storage::disk(config('chamma.disk'));
        $maxDimension = (int) config('chamma.uploads.max_dimension');
        $pngMinBytes = (int) config('chamma.uploads.png_jpeg_min_bytes');

        $files = collect($disk->allFiles())
            ->filter(fn (string $path): bool => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                self::RASTER_EXTENSIONS,
                true,
            ))
            // Thumbnails are derived files: they are written here, never read.
            ->reject(fn (string $path): bool => str_starts_with($path, 'uploads/thumbs/'));

        if ($files->isEmpty()) {
            $this->info('No raster images found on ['.config('chamma.disk').'].');

            return self::SUCCESS;
        }

        $this->bar = $this->output->createProgressBar($files->count());
        $this->bar->start();

        [$rewritten, $renamed, $thumbs, $bytes] = [0, 0, 0, 0];

        foreach ($files as $path) {
            $contents = (string) $disk->get($path);
            $info = @getimagesizefromstring($contents);

            // Corrupt/foreign files are left exactly as they were: the command
            // must never be the thing that empties the media bucket.
            if ($info === false) {
                $this->bar->advance();

                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $withinCeiling = max($info[0], $info[1]) <= $maxDimension;

            // Files already inside the ceiling can't gain from downscaling,
            // and re-encoding their bytes again is pure generation loss. The
            // one exception is a large PNG, whose weight is the codec rather
            // than the dimensions — that one is offered a JPEG conversion.
            // This is what makes repeated runs a no-op.
            $needsOptimize = ! $withinCeiling
                || ($extension === 'png' && strlen($contents) >= $pngMinBytes);

            $temp = $this->tempFrom($contents);
            $source = new UploadedFile($temp, pathinfo($path, PATHINFO_BASENAME), $info['mime'], UPLOAD_ERR_OK, true);

            $optimized = $needsOptimize ? $optimizer->optimize($source) : $source;
            $optimizedBytes = (string) file_get_contents($optimized->getRealPath());
            $thumbTemp = $optimizer->thumbnail($optimized);

            if ($optimizedBytes !== $contents) {
                $storedName = $optimized->getClientOriginalName();
                $directory = pathinfo($path, PATHINFO_DIRNAME);
                $newPath = ($directory === '.' ? '' : $directory.'/').$storedName;

                $disk->put($newPath, $optimizedBytes);

                if ($newPath !== $path) {
                    // The codec changed, so the name had to: drop the old file
                    // and move every reference across with it, snapshots and
                    // reviews included, or those records point at nothing.
                    $disk->delete($path);
                    $this->repoint($path, $newPath);
                    $renamed++;
                }

                $rewritten++;
                $bytes += strlen($contents) - strlen($optimizedBytes);
            }

            // Card thumbnails are only maintained for uploads/ — the product
            // artwork and generated collection images have no photo to shrink.
            if (str_starts_with($path, 'uploads/')) {
                $thumbPath = 'uploads/thumbs/'.$optimized->getClientOriginalName();

                if ($thumbTemp !== null && ! $disk->exists($thumbPath)) {
                    $disk->put($thumbPath, (string) file_get_contents($thumbTemp));
                    $thumbs++;
                }
            }

            if ($thumbTemp !== null) {
                @unlink($thumbTemp);
            }

            @unlink($optimized->getRealPath());

            if ($optimized->getRealPath() !== $temp) {
                @unlink($temp);
            }

            $this->bar->advance();
        }

        $this->bar->finish();
        $this->newLine(2);

        $format = fn (int $bytes): string => $bytes < 1024 * 1024
            ? ((int) ceil($bytes / 1024)).' KB'
            : sprintf('%.1f MB', $bytes / 1024 / 1024);

        $this->info(
            "{$rewritten} of {$files->count()} images rewritten ({$renamed} PNGs re-encoded as JPEG), ".
            "{$thumbs} thumbnails written, {$format(max(0, $bytes))} shed."
        );

        return self::SUCCESS;
    }

    /**
     * Move every stored reference from the old path to the rewritten one.
     * Each column holds an exact path (or null), so an equality match is the
     * whole job — no JSON unwrapping needed anywhere.
     */
    private function repoint(string $from, string $to): void
    {
        foreach (self::PATH_COLUMNS as $table => $column) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }

    private function tempFrom(string $contents): string
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'img-');
        file_put_contents($temp, $contents);

        return $temp;
    }
}
