<?php

/**
 * Backfill existing uploads through the image optimizer.
 *
 * Run: php artisan uploads:optimize
 *
 * The optimizer was wired into the upload endpoint after the store already
 * held raw phone-camera originals in public/images, so the storefront kept
 * serving multi-megapixel files until this pass rewrites them. Everything
 * raster on the storefront disk goes through the same shrink/re-encode the
 * endpoint applies now; SVG is skipped, and files are only touched when the
 * optimizer actually changes them. Safe to re-run: small images stay untouched.
 */

namespace App\Console\Commands;

use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class OptimizeUploads extends Command
{
    /** @var list<string> */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private ?ProgressBar $bar = null;

    protected $signature = 'uploads:optimize';

    protected $description = 'Downscale and re-encode every raster image already stored on the storefront disk';

    public function handle(ImageOptimizer $optimizer): int
    {
        $disk = Storage::disk(config('chamma.disk'));

        $files = collect($disk->allFiles())
            ->filter(fn (string $path): bool => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                self::RASTER_EXTENSIONS,
                true,
            ));

        if ($files->isEmpty()) {
            $this->info('No raster images found on ['.config('chamma.disk').'].');

            return self::SUCCESS;
        }

        $this->bar = $this->output->createProgressBar($files->count());
        $this->bar->start();

        [$resized, $bytes] = [0, 0];

        foreach ($files as $path) {
            $original = $disk->get($path);
            $info = @getimagesizefromstring((string) $original);

            // Corrupt/foreign files are left exactly as they were: the command
            // must never be the thing that empties the media bucket.
            if ($info === false) {
                $this->bar->advance();

                continue;
            }

            // Files already inside the ceiling can't gain from downscaling,
            // and re-encoding their bytes again is pure generation loss. This
            // is what makes repeated runs a no-op.
            if (max($info[0], $info[1]) <= config('chamma.uploads.max_dimension')) {
                $this->bar->advance();

                continue;
            }

            $temp = $this->tempFrom((string) $original);

            $optimized = $optimizer->optimize(new UploadedFile(
                $temp,
                pathinfo($path, PATHINFO_BASENAME),
                $info['mime'],
                UPLOAD_ERR_OK,
                true,
            ));

            $contents = file_get_contents($optimized->getRealPath());
            @unlink($optimized->getRealPath());
            @unlink($temp);

            if ($contents !== (string) $original) {
                $disk->put($path, $contents);
                $resized++;
                $bytes += strlen((string) $original) - strlen($contents);
            }

            $this->bar->advance();
        }

        $this->bar->finish();
        $this->newLine(2);

        $format = fn (int $bytes): string => $bytes < 1024 * 1024
            ? ((int) ceil($bytes / 1024)).' KB'
            : sprintf('%.1f MB', $bytes / 1024 / 1024);

        $this->info("{$resized} of {$files->count()} images rewritten, {$format(max(0, $bytes))} shed.");

        return self::SUCCESS;
    }

    private function tempFrom(string $contents): string
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'img-');
        file_put_contents($temp, $contents);

        return $temp;
    }
}
