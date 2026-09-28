<?php

namespace App\Services;

/**
 * Morocco delivery rules.
 *
 * The price comes from the carrier's own tariff for the destination city
 * (config/shipping_zones.php), so a Casablanca order costs what the carrier
 * charges for Casablanca rather than a single national average. The flat cost
 * stays as the fallback for a city the table does not list.
 */
class ShippingService
{
    public function __construct(private readonly ShippingZoneService $zones) {}

    public function cost(float $subtotal, string $city): float
    {
        if ($subtotal >= (float) config('chamma.shipping.free_threshold')) {
            return 0.0;
        }

        $config = config('chamma.shipping');

        // A listed city is priced by the carrier, surcharge included.
        $fee = $this->zones->feeFor($city);

        if ($fee !== null) {
            return round($fee, 2);
        }

        $cost = (float) $config['flat_cost'];

        if ($this->isRemote($city)) {
            $cost += (float) $config['remote_surcharge'];
        }

        return round($cost, 2);
    }

    public function isRemote(string $city): bool
    {
        $needle = $this->normalise($city);

        foreach (config('chamma.shipping.remote_cities') as $remote) {
            if ($this->normalise($remote) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function isFree(float $subtotal): bool
    {
        return $subtotal >= (float) config('chamma.shipping.free_threshold');
    }

    public function freeThreshold(): float
    {
        return (float) config('chamma.shipping.free_threshold');
    }

    public function remainingForFreeShipping(float $subtotal): float
    {
        return max(0.0, round($this->freeThreshold() - $subtotal, 2));
    }

    public function estimate(string $locale): string
    {
        return config('chamma.shipping.estimate_days.'.$locale)
            ?? config('chamma.shipping.estimate_days.en');
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return str_replace(['-', '’', ' '], ['', '', ''], $value);
    }
}
