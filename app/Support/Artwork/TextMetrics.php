<?php

namespace App\Support\Artwork;

/**
 * Text measurement for generated artwork.
 *
 * Labels must never overflow their panel, and the renderer must work inside a
 * slim container that may have no TrueType fonts installed. So: measure with a
 * real serif face when one is available, otherwise fall back to a per-glyph
 * width table calibrated for uppercase serif lettering.
 */
final class TextMetrics
{
    /** @var list<string> */
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
        '/usr/share/fonts/dejavu/DejaVuSerif.ttf',
        '/usr/share/fonts/TTF/DejaVuSerif.ttf',
        '/Library/Fonts/Georgia.ttf',
        '/System/Library/Fonts/Supplemental/Georgia.ttf',
        '/System/Library/Fonts/Supplemental/Times New Roman.ttf',
        'C:/Windows/Fonts/times.ttf',
    ];

    /** @var array<string, float> Uppercase serif advance widths, in em. */
    private const WIDTHS = [
        'A' => 0.72, 'B' => 0.67, 'C' => 0.72, 'D' => 0.75, 'E' => 0.65, 'F' => 0.62,
        'G' => 0.78, 'H' => 0.78, 'I' => 0.39, 'J' => 0.45, 'K' => 0.74, 'L' => 0.63,
        'M' => 0.95, 'N' => 0.78, 'O' => 0.79, 'P' => 0.64, 'Q' => 0.79, 'R' => 0.71,
        'S' => 0.62, 'T' => 0.68, 'U' => 0.76, 'V' => 0.72, 'W' => 1.02, 'X' => 0.72,
        'Y' => 0.70, 'Z' => 0.66,
        '0' => 0.58, '1' => 0.58, '2' => 0.58, '3' => 0.58, '4' => 0.58, '5' => 0.58,
        '6' => 0.58, '7' => 0.58, '8' => 0.58, '9' => 0.58,
        ' ' => 0.30, '.' => 0.29, ',' => 0.29, "'" => 0.22, '-' => 0.36, '&' => 0.78,
        '’' => 0.22, '×' => 0.6, '°' => 0.42, '/' => 0.35, '+' => 0.6,
    ];

    private static ?string $font = null;

    private static bool $probed = false;

    /**
     * Rendered width of $text at $fontSize, including per-character tracking.
     */
    public static function width(string $text, float $fontSize, float $letterSpacing = 0.0): float
    {
        $font = self::font();

        if ($font !== null) {
            $box = @imagettfbbox($fontSize, 0.0, $font, $text);

            if ($box !== false) {
                $width = ($box[2] - $box[0]) + ($letterSpacing * max(0, mb_strlen($text) - 1));

                if ($width > 0) {
                    return (float) $width;
                }
            }
        }

        $em = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $em += self::WIDTHS[$char] ?? 0.66;
        }

        return ($em * $fontSize) + ($letterSpacing * max(0, mb_strlen($text) - 1));
    }

    /**
     * Largest font size (capped at $max) at which $text still fits $maxWidth.
     * Tracking is relaxed on short strings and tightened on long ones so
     * lettering always looks deliberate rather than uniformly scaled.
     *
     * @return array{size: float, tracking: float}
     */
    public static function fit(string $text, float $maxWidth, float $max, float $tracking, float $min = 7.0): array
    {
        $size = $max;
        $track = $tracking;

        while ($size > $min) {
            $width = self::width($text, $size, $track);

            if ($width <= $maxWidth) {
                return ['size' => $size, 'tracking' => $track];
            }

            // Try relaxing tracking before shrinking type.
            if ($track > 0.6 && self::width($text, $size, 0.6) <= $maxWidth) {
                return ['size' => $size, 'tracking' => 0.6];
            }

            $size -= 1;
        }

        return ['size' => $min, 'tracking' => 0.4];
    }

    public static function font(): ?string
    {
        if (self::$probed) {
            return self::$font;
        }

        self::$probed = true;

        if (! function_exists('imagettfbbox')) {
            return self::$font = null;
        }

        $configured = config('chamma.artwork_font');

        $candidates = $configured ? [$configured, ...self::FONT_CANDIDATES] : self::FONT_CANDIDATES;

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return self::$font = $path;
            }
        }

        return self::$font = null;
    }
}
