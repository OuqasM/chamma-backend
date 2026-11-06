<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Shrinks admin uploads before they reach the storefront disk.
 *
 * Phone cameras hand us 5-12 megapixel originals; serving those straight from
 * public/images is what made the storefront slow to load. Anything whose
 * longest side exceeds the configured maximum is downscaled, JPEGs are always
 * re-encoded at moderate quality (progressive), and EXIF/metadata is dropped
 * along the way — good for both weight and privacy. SVG passes through
 * untouched, and a file GD cannot read or encode is stored as-is so an upload
 * never fails because of a missing codec.
 *
 * File names and extensions are preserved: the path returned to the admin
 * panel does not change.
 */
final class ImageOptimizer
{
    private readonly int $maxDimension;

    private readonly int $quality;

    public function __construct()
    {
        $this->maxDimension = max(64, (int) config('chamma.uploads.max_dimension', 1920));
        $this->quality = max(10, min(95, (int) config('chamma.uploads.quality', 85)));
    }

    /**
     * @return UploadedFile the same instance when untouched, otherwise one
     *                      backed by an optimized temp file.
     */
    public function optimize(UploadedFile $file): UploadedFile
    {
        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) === 'svg') {
            return $file;
        }

        $temp = $this->rebuild($file->getRealPath());

        // The source temp file is PHP's own upload buffer: it is removed when
        // the request ends, so nothing needs unlinking here.
        if ($temp === null) {
            return $file;
        }

        return new UploadedFile(
            $temp,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            UPLOAD_ERR_OK,
            true,
        );
    }

    /**
     * Rebuild the source image into a fresh temp file, or null when the file
     * was left as-is.
     *
     * @return string|null path of the written temp file
     */
    private function rebuild(string $source): ?string
    {
        $info = @getimagesize($source);

        if ($info === false) {
            return null;
        }

        $type = $info[2];

        // WebP is only processed when the build of GD carries webp support;
        // the production image is configured without it, so webp passes
        // through (it is already a small codec) instead of erroring.
        $supported = [
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg'),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng'),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
        ];

        if (! isset($supported[$type]) || $supported[$type] !== true) {
            return null;
        }

        [$width, $height] = $info;

        $previousLimit = $this->reserveMemory($width, $height);

        try {
            $image = $this->decode($source, $type);

            if ($image === null) {
                return null;
            }

            if ($type === IMAGETYPE_JPEG) {
                $image = $this->straighten($source, $image);
            }

            $image = $this->rescale($image);

            // PNGs and WebPs keep their bytes when nothing needed rescaling:
            // re-encoding those loses alpha fidelity for no real gain. JPEGs
            // always pass through the encoder for the predictable weight.
            if ($type !== IMAGETYPE_JPEG && max(imagesx($image), imagesy($image)) === max($width, $height)) {
                imagedestroy($image);

                return null;
            }

            $temp = (string) tempnam(sys_get_temp_dir(), 'img-');
            $written = match ($type) {
                IMAGETYPE_JPEG => (function () use ($image, $temp): bool {
                    imageinterlace($image, 1); // progressive scan

                    return imagejpeg($image, $temp, $this->quality);
                })(),
                IMAGETYPE_PNG => imagepng($image, $temp, 6),
                default => imagewebp($image, $temp, $this->quality),
            };

            imagedestroy($image);

            if ($written !== true) {
                @unlink($temp);

                return null;
            }

            // Only a genuine win is kept. An already-tight file would just be
            // handed one extra generation of compression (and could even
            // grow), so the original bytes ship instead.
            if (@filesize($temp) >= @filesize($source)) {
                @unlink($temp);

                return null;
            }

            return $temp;
        } finally {
            if ($previousLimit !== null) {
                ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /**
     * @return GdImage|null null when GD could not decode the pixels
     */
    private function decode(string $source, int $type): ?GdImage
    {
        $image = @match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG => imagecreatefrompng($source),
            IMAGETYPE_WEBP => imagecreatefromwebp($source),
        };

        if ($image === false) {
            return null;
        }

        // Palette PNGs (8-bit logos) decode without an alpha channel; moving
        // them to truecolor keeps transparency readable through the resample.
        if ($type === IMAGETYPE_PNG) {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        return $image;
    }

    /**
     * Respect EXIF orientation so rotated phone photos keep looking right
     * after re-encoding strips their metadata. A missing EXIF extension (or
     * a straight-out-of-camera image) keeps the pixels untouched.
     */
    private function straighten(string $source, GdImage $image): GdImage
    {
        $orientation = 1;

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source, 'EXIF', false, false);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }

        // Mirrored sides appear on camera-selfies the sensor never flattened.
        if ($orientation === 2 || $orientation === 5 || $orientation === 7) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 8 => imagerotate($image, 90, 0),
            6, 7 => imagerotate($image, -90, 0),
            default => $image,
        };

        if ($rotated === false || $rotated === null || $rotated === $image) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Downscale when the longest side exceeds the ceiling. Never upscales: a
     * small logo must stay crisp.
     *
     * @return GdImage the same image when within bounds, otherwise the pixels
     *                 of the original (which is freed here).
     */
    private function rescale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $this->maxDimension) {
            return $image;
        }

        $factor = $this->maxDimension / $longest;
        $targetWidth = (int) max(1, round($width * $factor));
        $targetHeight = (int) max(1, round($height * $factor));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        // Keeping alpha sane for transparent PNGs/WebPs along the resample.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Raise the memory ceiling when the decoded pixels would blow past the
     * current limit (a 4032px iPhone still needs ~190MB just to decode).
     *
     * @return string|null the previous memory_limit value, when we changed it
     */
    private function reserveMemory(int $width, int $height): ?string
    {
        $previous = ini_get('memory_limit');

        // Truecolor decode plus resample buffers peak near ~4x pixel area;
        // 8x keeps encoder headroom on top.
        $bytes = $width * $height * 8;

        if ($bytes < 48 * 1024 * 1024) {
            return null;
        }

        if ($this->limitToBytes($previous) >= $bytes) {
            return null;
        }

        ini_set('memory_limit', sprintf('%dM', (int) ceil($bytes / 1024 / 1024)));

        return $previous;
    }

    /**
     * @return int parsed memory_limit value in bytes, or -1 for unlimited
     */
    private function limitToBytes(string|false $limit): int
    {
        if ($limit === false || $limit === '') {
            return 128 * 1024 * 1024;
        }

        if ($limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
