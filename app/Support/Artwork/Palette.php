<?php

namespace App\Support\Artwork;

/**
 * Colour language of the boutique: ivory, warm white, soft beige, champagne,
 * espresso, black — gold strictly as an accent.
 */
final class Palette
{
    /** @var array<string, array<string, string>> */
    public const TONES = [
        'blush' => [
            'bg_top' => '#FDF6F3', 'bg_bottom' => '#F3DED6', 'glow' => '#FFF8F4',
            'body_light' => '#F3D3CB', 'body_mid' => '#DFA79C', 'body_dark' => '#9A5C55',
            'liquid' => '#C97C73', 'accent' => '#B08A5A', 'ink' => '#5C2E2B',
        ],
        'rose' => [
            'bg_top' => '#FBF1F2', 'bg_bottom' => '#EBD2D5', 'glow' => '#FFF9F9',
            'body_light' => '#E9BFC4', 'body_mid' => '#C98A93', 'body_dark' => '#7F4550',
            'liquid' => '#A85E68', 'accent' => '#B08A5A', 'ink' => '#4E2129',
        ],
        'amber' => [
            'bg_top' => '#FDF7EC', 'bg_bottom' => '#F0E0C4', 'glow' => '#FFFBF3',
            'body_light' => '#F2DDB2', 'body_mid' => '#D8AC63', 'body_dark' => '#8A6127',
            'liquid' => '#C08A2E', 'accent' => '#A8834B', 'ink' => '#4A3313',
        ],
        'espresso' => [
            'bg_top' => '#F6F1EA', 'bg_bottom' => '#E2D5C6', 'glow' => '#FFFBF6',
            'body_light' => '#B99C82', 'body_mid' => '#7E5A44', 'body_dark' => '#3E2A1E',
            'liquid' => '#5A3A28', 'accent' => '#C8A96A', 'ink' => '#2A1A12',
        ],
        'ivory' => [
            'bg_top' => '#FEFDFB', 'bg_bottom' => '#EFE9E0', 'glow' => '#FFFFFF',
            'body_light' => '#F5EFE6', 'body_mid' => '#DFD3C2', 'body_dark' => '#A08C74',
            'liquid' => '#D9C9B2', 'accent' => '#B08A5A', 'ink' => '#3B322A',
        ],
        'plum' => [
            'bg_top' => '#F7F1F6', 'bg_bottom' => '#E3D3E1', 'glow' => '#FFFAFF',
            'body_light' => '#C9AECB', 'body_mid' => '#8E6A94', 'body_dark' => '#4A2F52',
            'liquid' => '#6B4473', 'accent' => '#C8A96A', 'ink' => '#2F1B36',
        ],
        'jade' => [
            'bg_top' => '#F2F7F3', 'bg_bottom' => '#D6E4D9', 'glow' => '#FAFFFB',
            'body_light' => '#BFD6C6', 'body_mid' => '#87A98F', 'body_dark' => '#3F5B48',
            'liquid' => '#5E7F68', 'accent' => '#B08A5A', 'ink' => '#1F2E24',
        ],
        'noir' => [
            'bg_top' => '#F4F2EF', 'bg_bottom' => '#D9D3CC', 'glow' => '#FFFFFF',
            'body_light' => '#6B6560', 'body_mid' => '#332F2C', 'body_dark' => '#141211',
            'liquid' => '#221F1D', 'accent' => '#C8A96A', 'ink' => '#100E0D',
        ],
    ];

    /** Champagne gold ramp shared by caps, rules and emblems. */
    public const GOLD = ['#F0DDB4', '#D9B979', '#C8A96A', '#A8834B'];

    /**
     * @return array<string, string>
     */
    public static function tone(?string $name): array
    {
        return self::TONES[$name] ?? self::TONES['ivory'];
    }

    public static function tint(string $hex, float $amount, string $direction = 'light'): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $target = $direction === 'light' ? 255 : 0;
        $ratio = $direction === 'light' ? $amount : 1 - $amount;

        return self::hex(
            (int) round($r + ($target - $r) * $ratio),
            (int) round($g + ($target - $g) * $ratio),
            (int) round($b + ($target - $b) * $ratio),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
