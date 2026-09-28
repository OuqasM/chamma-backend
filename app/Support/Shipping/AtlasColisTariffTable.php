<?php

namespace App\Support\Shipping;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Reads the carrier's public tariff table (https://atlascolis.ma/tarifs.php).
 *
 * The page is a single table whose columns are, in the carrier's own wording:
 *
 *     Villes | Delai | Frais De Livraison | Frais De Refuse | Frais De Retour
 *
 * Only "Frais De Livraison" is what a shopper pays; the refusal and return
 * columns are kept because they are the same table and cost nothing to carry.
 *
 * Parsing is deliberately layout-tolerant — any `tr` holding at least five
 * `td` cells is a tariff row — because the carrier re-skins the page without
 * warning. Anything that does not look like the tariff table yields no rows,
 * which is the signal the import command refuses to write on.
 */
final class AtlasColisTariffTable
{
    public const SOURCE = 'https://atlascolis.ma/tarifs.php';

    /**
     * @return array<int, array{code: string, city: string, fee: float, delay: ?string, refusal_fee: float, return_fee: float}>
     */
    public function parse(string $html): array
    {
        $document = $this->load($html);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $zones = [];

        // `tr[td]` skips the header row, which uses `th`.
        foreach ($xpath->query('//tr[td]') ?: [] as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];

            foreach ($xpath->query('./td', $row) ?: [] as $cell) {
                $cells[] = $this->text($cell->textContent);
            }

            // Fewer cells than columns: a layout we do not understand, and a
            // misread fee is worse than a missing row.
            if (count($cells) < 5) {
                continue;
            }

            $city = $cells[0];

            if ($city === '') {
                continue;
            }

            $zones[] = [
                // The carrier's own zone id, e.g. `city-BML` -> `BML`. Not
                // unique across the table, so lookups go by city name.
                'code' => $this->code($row, $city),
                'city' => $city,
                'fee' => $this->money($cells[2]),
                'delay' => $this->delay($cells[1]),
                'refusal_fee' => $this->money($cells[3]),
                'return_fee' => $this->money($cells[4]),
            ];
        }

        return $zones;
    }

    /**
     * The carrier posts a handful of duplicate cities; the first (cheapest)
     * row wins, same rule as ShippingZoneService.
     *
     * @param  array<int, array{code: string, city: string, fee: float, delay: ?string, refusal_fee: float, return_fee: float}>  $zones
     * @return array{added: array<int, string>, removed: array<int, string>, changed: array<int, array{city: string, from: float, to: float}>, total: int}
     */
    public function diff(array $current, array $incoming): array
    {
        $index = function (array $zones): array {
            $out = [];

            foreach ($zones as $zone) {
                $key = $this->key($zone['city']);

                if (! isset($out[$key]) || $zone['fee'] < $out[$key]['fee']) {
                    $out[$key] = $zone;
                }
            }

            return $out;
        };

        $before = $index($current);
        $after = $index($incoming);

        $added = [];
        $changed = [];

        foreach ($after as $key => $zone) {
            if (! isset($before[$key])) {
                $added[] = $this->text($zone['city']);
            } elseif ((float) $before[$key]['fee'] !== (float) $zone['fee']) {
                $changed[] = [
                    'city' => $this->text($zone['city']),
                    'from' => (float) $before[$key]['fee'],
                    'to' => (float) $zone['fee'],
                ];
            }
        }

        $removed = [];

        foreach ($before as $key => $zone) {
            if (! isset($after[$key])) {
                $removed[] = $this->text($zone['city']);
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'total' => count($after),
        ];
    }

    /**
     * City names as the service indexes them, so the diff matches lookups.
     */
    private function key(string $city): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower(trim($city))));
    }

    /**
     * "city-BML" -> "BML". The carrier pads a few ids ("city- tala youssef "),
     * so strip the prefix first and trim after; city names keep their own
     * spaces and accents. Rows without an id fall back to the city name.
     */
    private function code(DOMElement $row, string $city): string
    {
        $id = trim($row->getAttribute('id'));

        if ($id !== '' && ! ctype_digit($id)) {
            return trim((string) preg_replace('/^city[-_]/i', '', $id));
        }

        return $city;
    }

    /**
     * " 20 DH" -> 20.0, "Gratuit" -> 0.0.
     */
    private function money(string $value): float
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $value, $m) !== 1) {
            return 0.0;
        }

        return (float) str_replace(',', '.', $m[0]);
    }

    /**
     * "24H" is kept verbatim; an empty cell is an unknown delay, not a zero.
     */
    private function delay(string $value): ?string
    {
        $value = $this->text($value);

        return in_array($value, ['', '-', '—'], true) ? null : $value;
    }

    private function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Returns null when the payload is not HTML at all (a captive portal, a
     * proxy error page, an empty body).
     */
    private function load(string $html): ?DOMDocument
    {
        if (trim($html) === '' || ! str_contains($html, '<')) {
            return null;
        }

        // The carrier serves UTF-8, but honour a declared charset so a switch
        // to Latin-1 does not mangle every city name.
        if (preg_match('/charset=["\']?([a-z0-9_-]+)/i', substr($html, 0, 2048), $m) === 1) {
            $charset = strtoupper($m[1]);

            if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

                if (is_string($converted) && $converted !== '') {
                    $html = $converted;
                }
            }
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET keeps the parser from resolving anything off-box.
        $loaded = $document->loadHTML($html, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
