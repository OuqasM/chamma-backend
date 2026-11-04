<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the shop took and what it earned.
 *
 * Two things are answered here: the **benefit** of an order — its total minus
 * the purchase price of what is in it — and the figures over a filtered set of
 * orders, which is what the owner asks when they want to know how the month
 * went.
 *
 * And the delivery fee: what the owner charges for a city is **kept for that
 * city**, so the next order to it is billed the same way.
 */
class AdminSalesFiguresTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    /**
     * A product with a known purchase price, so the benefit is a fact rather
     * than a guess.
     */
    private function product(string $name = 'Oud Royale', float $price = 890, float $cost = 500): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', [
            'name' => $name,
            'price' => $price,
            'cost_price' => $cost,
            'stock' => 10,
        ])->assertCreated();

        return Product::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // The benefit of an order
    // ---------------------------------------------------------------

    /**
     * The benefit is the turnover minus what the goods cost: the order's total
     * minus the purchase price of every one of its products.
     */
    public function test_the_benefit_is_the_total_minus_the_purchase_price(): void
    {
        $product = $this->product(price: 890, cost: 500);

        // Two at 890 is 1780 of goods, plus the 35 delivery fee.
        $response = $this->postJson('/api/admin/orders', $this->payload($product))
            ->assertCreated();

        $response->assertJsonPath('order.subtotal', 1780);
        $response->assertJsonPath('order.shipping_cost', 35);
        $response->assertJsonPath('order.total', 1815);
        // And the goods cost 1000, so what is left is 815.
        $response->assertJsonPath('order.cost', 1000);
        $response->assertJsonPath('order.benefit', 815);
    }

    /**
     * The purchase price is a snapshot, like the selling price: the benefit of
     * an order is the benefit it had when it was placed.
     */
    public function test_the_purchase_price_is_snapshotted_on_the_line(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        $line = Order::query()->firstOrFail()->items->first();
        $this->assertSame('500.00', $line->unit_cost);
    }

    /**
     * A product without a purchase price has no benefit to speak of, so the
     * panel asks for one.
     */
    public function test_a_product_needs_its_purchase_price(): void
    {
        $this->admin();

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('cost_price');
    }

    // ---------------------------------------------------------------
    // The figures over a filtered set
    // ---------------------------------------------------------------

    /**
     * What the shop took and what it earned, over the orders the filter
     * selects — not over the page of rows the owner happens to be looking at.
     */
    public function test_the_orders_carry_the_total_sales_and_the_benefit(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();
        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345679',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();

        $response = $this->getJson('/api/admin/orders')->assertOk();

        // Two orders: 1815 and 925.
        $response->assertJsonPath('summary.orders', 2);
        $response->assertJsonPath('summary.total_sales', 2740);
        // The goods cost 1500 (two at 500 and one at 500).
        $response->assertJsonPath('summary.total_cost', 1500);
        $response->assertJsonPath('summary.total_benefit', 1240);
    }

    /**
     * A window of dates. A date on its own means the whole of that day: "from
     * the 1st" is the 1st, not midnight on the 1st with every order of the
     * morning missing.
     */
    public function test_the_orders_can_be_filtered_by_date(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        $today = now()->toDateString();

        $this->getJson('/api/admin/orders?start_date='.$today.'&end_date='.$today)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.orders', 1);

        $this->getJson('/api/admin/orders?start_date='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/orders?end_date='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.orders', 0)
            ->assertJsonPath('summary.total_sales', 0);
    }

    // ---------------------------------------------------------------
    // The fee is kept for the city
    // ---------------------------------------------------------------

    /**
     * What the owner charges for a city is kept for that city: the next
     * order there is billed the same way, without anyone typing it again.
     */
    public function test_the_fee_is_kept_for_the_city(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 20,
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 20);

        // The next order to the same city, with nothing said about the fee.
        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345679',
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 20);

        // Another city is untouched.
        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345677',
            'city' => 'RABAT',
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 35);
    }

    /**
     * A free delivery is kept like any other price — until it is changed
     * back.
     */
    public function test_a_free_delivery_can_be_kept_for_the_city(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 0,
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 0);

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345679',
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 0);
    }

    /**
     * A free delivery for **one order** is not a decision about the whole
     * city, and the panel says which of the two it means.
     */
    public function test_a_free_delivery_for_one_order_does_not_price_the_city(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 0,
            'remember_fee' => false,
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 0);

        // The city still costs what the carrier charges.
        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345679',
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 35);
    }

    /**
     * The way back: the carrier's price stands again.
     */
    public function test_a_city_can_be_put_back_on_the_carriers_price(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 20,
        ]))->assertCreated();

        $this->getJson('/api/admin/shipping/fees')
            ->assertOk()
            ->assertJsonPath('fees.CASABLANCA', 20);

        $this->deleteJson('/api/admin/shipping/fees/CASABLANCA')->assertOk();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'phone' => '0612345679',
        ]))->assertCreated()->assertJsonPath('order.shipping_cost', 35);
    }

    /**
     * The storefront quotes what the shop charges: a shopper told 35 and
     * charged 20 has been told two different things.
     */
    public function test_the_storefront_quotes_the_shops_price_for_the_city(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 20,
        ]))->assertCreated();

        $options = $this->getJson('/api/fr/checkout/options')->assertOk();
        $casablanca = collect($options->json('cities'))->firstWhere('value', 'CASABLANCA');

        $this->assertSame(20.0, (float) $casablanca['fee']);

        // And the quote agrees with it.
        $quote = $this->postJson('/api/fr/checkout/quote', [
            'city' => 'CASABLANCA',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(20.0, (float) $quote->json('shipping_cost'));
    }
}
