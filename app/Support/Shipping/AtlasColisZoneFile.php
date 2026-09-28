<?php

namespace App\Support\Shipping;

use RuntimeException;

/**
 * Renders scraped zones into config/shipping_zones.php.
 *
 * Writing PHP by hand is the riskiest part of the import: a malformed file is
 * not a parse error the store would notice, it is a dead checkout (the config
 * returns an int instead of the array and every city lookup breaks). PHP's
 * linter does not help — a file missing its `<?php` is valid inline HTML.
 *
 * So rendering and verification live together: render() produces the source,
 * verify() loads it the way Laravel will and rejects anything malformed before
 * the file is moved into place.
 */
final class AtlasColisZoneFile
{
    public const PATH = 'shipping_zones.php';

    /**
     * @param  array<int, array{code: string, city: string, fee: float, delay: ?string, refusal_fee: float, return_fee: float}>  $zones
     */
    public static function render(array $zones, string $source, string $fetchedAt): string
    {
        $rows = '';

        foreach ($zones as $zone) {
            $rows .= sprintf(
                "        ['code' => %s, 'city' => %s, 'fee' => %s, 'delay' => %s, 'refusal_fee' => %s, 'return_fee' => %s],\n",
                var_export((string) $zone['code'], true),
                var_export((string) $zone['city'], true),
                self::number($zone['fee']),
                $zone['delay'] === null ? 'null' : var_export((string) $zone['delay'], true),
                self::number($zone['refusal_fee']),
                self::number($zone['return_fee']),
            );
        }

        return "<?php\n\n".self::comment($source, $fetchedAt, count($zones))."\n".$rows."    ],\n];\n";
    }

    /**
     * Loads a rendered file exactly as the application will, and insists it is
     * a usable tariff file. Returns the row count.
     *
     * @throws RuntimeException when the file is unusable
     */
    public static function verify(string $path, ?int $expectedRows = null): int
    {
        try {
            // Buffered because a file missing its `<?php` tag is pure inline
            // HTML, which `require` would print straight to the output.
            ob_start();

            try {
                $data = require $path;
            } finally {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('%s is not valid PHP (%s).', basename($path), $e->getMessage()),
                0,
                $e,
            );
        }

        if (! is_array($data) || ! isset($data['zones']) || ! is_array($data['zones'])) {
            throw new RuntimeException(
                sprintf('%s did not return an array with a "zones" list — it is missing its <?php tag or is otherwise not a config file.', basename($path))
            );
        }

        if ($expectedRows !== null && count($data['zones']) !== $expectedRows) {
            throw new RuntimeException(sprintf(
                '%s holds %d rows, expected %d.', basename($path), count($data['zones']), $expectedRows
            ));
        }

        foreach ($data['zones'] as $i => $zone) {
            if (! is_array($zone) || ! isset($zone['city'], $zone['fee'], $zone['code'])) {
                throw new RuntimeException(sprintf('%s row %d is missing city/fee/code.', basename($path), $i + 1));
            }

            if ((float) $zone['fee'] <= 0) {
                throw new RuntimeException(sprintf('%s row %d (%s) has no delivery fee.', basename($path), $i + 1, $zone['city']));
            }
        }

        return count($data['zones']);
    }

    private static function number(float $value): string
    {
        return $value == (int) $value ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function comment(string $source, string $fetchedAt, int $count): string
    {
        return <<<PHP
        /*
        |--------------------------------------------------------------------------
        | Carrier delivery zones (Atlas Colis)
        |--------------------------------------------------------------------------
        |
        | GENERATED FILE — do not edit by hand. Refresh it with:
        |
        |     php artisan shipping:import-atlas           (writes the file)
        |     php artisan shipping:import-atlas --dry-run (reports the diff)
        |
        | Mirrored from {$source} on {$fetchedAt}: {$count} rows, one per delivery
        | city, with the fee the carrier actually charges for it.
        |
        | This is the single source of truth for:
        |
        |   - the city <select> on the checkout page;
        |   - the shipping cost of every order (see ShippingService);
        |   - the "deliverable" check, so an order is never taken for a city
        |     the carrier does not serve.
        |
        | `code` is the carrier's own zone id and is not unique (it reuses a
        | few), so lookups go through ShippingZoneService, which indexes the
        | rows by city name.
        |
        */

        return [
            'source' => '{$source}',
            'fetched_at' => '{$fetchedAt}',

            // code  | city | fee | delay | refusal | return
            'zones' => [

        PHP;
    }
}
