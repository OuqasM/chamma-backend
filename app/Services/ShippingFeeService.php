<?php

namespace App\Services;

use App\Models\DeliveryFee;
use Illuminate\Support\Facades\Cache;

/**
 * The price the shop charges for delivering to a city.
 *
 * The carrier's table says what the courier charges; that is the price an
 * order is normally billed at. But the shop sometimes charges something else
 * for a city — a negotiated rate, a flat fee for the far ones, a free
 * delivery for the regulars — and the owner should not have to type it again
 * for every order to that city.
 *
 * So the figure the owner puts in the order form is **kept for that city**,
 * and it is what every later order to that city is billed at, on the
 * storefront as much as in the panel.
 *
 * The prices live in a table of their own — one row per city — because they
 * are a fact about the shop rather than a stray setting: they are what the
 * customer is charged, what the courier is paid, and what the benefit of
 * every order to that city is settled against. There are a handful of them,
 * they are read on the checkout path, and the list is cached and forgotten on
 * a write.
 */
class ShippingFeeService
{
    /**
     * How long a cached block of overrides is good for. Short enough that a
     * change the owner makes takes effect at once, long enough that a
     * checkout is not two queries.
     */
    private const TTL = 300;

    /**
     * The shop's price for a city, or null when the carrier's price stands.
     */
    public function for(string $city): ?float
    {
        $city = trim($city);

        if ($city === '') {
            return null;
        }

        $value = $this->overrides()[$city] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * Every city the shop has priced for itself, and what it charges.
     *
     * @return array<string, float>
     */
    public function overrides(): array
    {
        return Cache::remember('store.shipping_fees', self::TTL, function () {
            $fees = [];

            foreach (DeliveryFee::query()->get() as $fee) {
                $fees[$fee->city] = (float) $fee->fee;
            }

            return $fees;
        });
    }

    /**
     * Keep a city's price.
     *
     * A zero is a price like any other — a free delivery for a city is a
     * decision, and it is kept like one.
     */
    public function set(string $city, float $fee): void
    {
        $city = trim($city);

        if ($city === '') {
            return;
        }

        DeliveryFee::query()->updateOrCreate(
            ['city' => $city],
            ['fee' => round($fee, 2)],
        );

        $this->flush();
    }

    /**
     * Go back to the carrier's price for a city.
     */
    public function forget(string $city): void
    {
        DeliveryFee::query()->where('city', trim($city))->delete();

        $this->flush();
    }

    private function flush(): void
    {
        Cache::forget('store.shipping_fees');
    }
}
