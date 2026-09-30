<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-city delivery pricing.
 *
 * The free-shipping threshold is the part worth pinning down. It used to default
 * to 700 MAD and short-circuit `cost()` before the carrier tariff was ever
 * consulted, so any basket over 700 MAD showed "Offerte" regardless of which
 * city it was going to — while the checkout city picker displayed that same
 * city's real fee a few centimetres above it. Two different numbers for one
 * delivery, from two different sources.
 */
class ShippingPricingTest extends TestCase
{
    use RefreshDatabase;

    private function shipping(): ShippingService
    {
        return app(ShippingService::class);
    }

    public function test_a_listed_city_is_priced_by_its_own_tariff(): void
    {
        // DAKHLA is in the carrier table at 50, well above the 35 flat fallback.
        $this->assertSame(50.0, $this->shipping()->cost(100, 'DAKHLA'));
    }

    /**
     * The regression that started this: a large basket must not silently become
     * free delivery and contradict the fee shown in the city picker.
     */
    public function test_a_large_basket_is_still_charged_the_city_fee(): void
    {
        $shipping = $this->shipping();

        $this->assertSame(
            $shipping->cost(100, 'DAKHLA'),
            $shipping->cost(5000, 'DAKHLA'),
            'a big basket was made free, overriding the city tariff',
        );
        $this->assertGreaterThan(0, $shipping->cost(5000, 'DAKHLA'));
    }

    /**
     * A threshold of 0 disables the promotion; it does not mean every basket
     * qualifies. Comparing `$subtotal >= 0` literally made shipping free always.
     */
    public function test_a_zero_threshold_does_not_make_everything_free(): void
    {
        config(['chamma.shipping.free_threshold' => 0]);

        $shipping = $this->shipping();

        $this->assertFalse($shipping->isFree(0));
        $this->assertFalse($shipping->isFree(5000));
        $this->assertGreaterThan(0, $shipping->cost(5000, 'DAKHLA'));
    }

    public function test_the_threshold_still_works_when_explicitly_configured(): void
    {
        config(['chamma.shipping.free_threshold' => 700]);

        $shipping = $this->shipping();

        $this->assertFalse($shipping->isFree(699));
        $this->assertTrue($shipping->isFree(700));
        $this->assertTrue($shipping->isFree(900));
        $this->assertSame(0.0, $shipping->cost(700, 'DAKHLA'));
        $this->assertSame(50.0, $shipping->cost(699, 'DAKHLA'));
    }

    /**
     * With the promotion off there is nothing to earn towards, so this must not
     * read as "0 MAD away from free shipping" on every basket.
     */
    public function test_remaining_for_free_shipping_is_zero_when_disabled(): void
    {
        config(['chamma.shipping.free_threshold' => 0]);

        $this->assertSame(0.0, $this->shipping()->remainingForFreeShipping(100));
    }

    public function test_remaining_for_free_shipping_counts_down_when_enabled(): void
    {
        config(['chamma.shipping.free_threshold' => 700]);

        $shipping = $this->shipping();

        $this->assertSame(600.0, $shipping->remainingForFreeShipping(100));
        $this->assertSame(0.0, $shipping->remainingForFreeShipping(700));
    }

    /**
     * The remote surcharge only applies to a city the carrier table does not
     * list: Boujdour has no row, so it falls back to 35 plus the 20 surcharge.
     */
    public function test_an_unlisted_remote_city_gets_the_surcharge(): void
    {
        $this->assertSame(55.0, $this->shipping()->cost(100, 'BOUJDOUR'));
    }

    /**
     * The quote endpoint is what the checkout totals are actually built from, so
     * this is the assertion that matters to a shopper: the shipping line on the
     * order summary must match the city's fee.
     */
    public function test_the_quote_endpoint_returns_the_city_fee(): void
    {
        // There is no ProductFactory in this codebase; products are created the
        // way an admin would, through the authenticated endpoint.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => 100,
            'stock' => 100,
        ])->assertCreated();

        $product = Product::query()->firstOrFail();

        $response = $this->postJson('/api/fr/checkout/quote', [
            'city' => 'DAKHLA',
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertOk();

        // 30 x 100 = 3000 MAD: over the old 700 threshold, so this is the exact
        // basket that used to come back as free.
        $this->assertSame(3000.0, (float) $response->json('subtotal'));
        $this->assertSame(50.0, (float) $response->json('shipping_cost'));
        $this->assertSame(3050.0, (float) $response->json('total'));
        $this->assertFalse((bool) $response->json('is_free_shipping'));
    }
}
