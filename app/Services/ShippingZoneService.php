<?php

namespace App\Services;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The delivery areas the store actually ships to, with the carrier's own
 * tariff for each one (see config/shipping_zones.php).
 *
 * The carrier table is the authority on *where* an order may go and *how much*
 * delivery costs. Everything else — the checkout city list, the live quote, the
 * admin's order list — reads from here, so a tariff change is one data edit.
 *
 * Lookups are accent/case/spacing insensitive on purpose: shoppers type or pick
 * "Casablanca", "casablanca" or "Casablanca " and it must resolve to the same
 * zone.
 */
class ShippingZoneService
{
    /**
     * Normalised city name => zone. Built once per request.
     *
     * @var array<string, array{name: string, code: string, fee: float, delay: ?string}>
     */
    private ?array $index = null;

    /**
     * Deliverable cities, deduplicated and sorted by name.
     *
     * @return array<int, array{name: string, code: string, fee: float, delay: ?string}>
     */
    public function all(): array
    {
        $zones = [];

        foreach ($this->rows() as $zone) {
            $key = $this->normalise($zone['city']);

            // The carrier table repeats a few cities; the first (cheapest) row
            // wins so a city never gets billed twice.
            if (! isset($zones[$key]) || $zone['fee'] < $zones[$key]['fee']) {
                $zones[$key] = [
                    'name' => $zone['city'],
                    'code' => $zone['code'],
                    'fee' => (float) $zone['fee'],
                    'delay' => $zone['delay'] ?: null,
                ];
            }
        }

        $zones = array_values($zones);

        // Natural order so "El Jadida" sorts before "El Ksar", and Fès lands
        // next to Figuig rather than after every F-word.
        usort($zones, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $zones;
    }

    /**
     * The city names, for validation and for the checkout <select>.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_column($this->all(), 'name');
    }

    /**
     * @return array{name: string, code: string, fee: float, delay: ?string}|null
     */
    public function find(string $city): ?array
    {
        $key = $this->normalise($city);

        return $this->index()[$key] ?? null;
    }

    public function supports(string $city): bool
    {
        return $this->find($city) !== null;
    }

    public function feeFor(string $city): ?float
    {
        return $this->find($city)['fee'] ?? null;
    }

    /**
     * How long the carrier takes to deliver to a city, when it publishes one.
     */
    public function delayFor(string $city): ?string
    {
        return $this->find($city)['delay'] ?? null;
    }

    /**
     * The canonical spelling of a city as the carrier writes it, or null when
     * the store does not deliver there.
     */
    public function canonical(string $city): ?string
    {
        return $this->find($city)['name'] ?? null;
    }

    /**
     * City options for the storefront, translated where a translation exists.
     *
     * The carrier table is written in Latin script and is not translated, so
     * lang/geo.php is only an override for the handful of major cities whose
     * spelling differs from the carrier's (Fès, Béni Mellal, Témara, ...).
     *
     * @return array<int, array{value: string, label: string, fee: float, delay: ?string}>
     */
    public function options(string $locale): array
    {
        return array_map(fn (array $zone) => [
            'value' => $zone['name'],
            'label' => $this->label($zone['name'], $locale),
            'fee' => $zone['fee'],
            'delay' => $zone['delay'],
        ], $this->all());
    }

    /**
     * The translated city name, falling back to the carrier's own spelling.
     */
    private function label(string $city, string $locale): string
    {
        $key = 'geo.cities.'.$city;

        return Lang::has($key, $locale) ? __($key, [], $locale) : $city;
    }

    /**
     * @return array<string, array{name: string, code: string, fee: float, delay: ?string}>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->rows() as $zone) {
            $key = $this->normalise($zone['city']);

            if (! isset($index[$key]) || $zone['fee'] < $index[$key]['fee']) {
                $index[$key] = [
                    'name' => $zone['city'],
                    'code' => $zone['code'],
                    'fee' => (float) $zone['fee'],
                    'delay' => $zone['delay'] ?: null,
                ];
            }
        }

        return $this->index = $index;
    }

    /**
     * @return array<int, array{code: string, city: string, fee: float, delay: ?string, refusal_fee: float, return_fee: float}>
     */
    private function rows(): array
    {
        return config('shipping_zones.zones', []);
    }

    /**
     * "El Jadida", "el jadida" and "EL-JADIDA" are one city.
     */
    private function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower(trim($value))));
    }
}
