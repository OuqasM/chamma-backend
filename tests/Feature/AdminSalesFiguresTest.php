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
        // And the goods cost 1000, the courier 35: what is left is 780.
        $response->assertJsonPath('order.cost', 1000);
        $response->assertJsonPath('order.courier_cost', 35);
        $response->assertJsonPath('order.benefit', 780);
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

    /**
     * The benefit is settled when the sale happens.
     *
     * An order the owner writes in the panel is delivered from the start, so
     * it has its benefit at once. A website order has none until it is
     * delivered: the goods have not been sold yet, and saying so is truer
     * than a figure that may still change.
     */
    public function test_a_website_order_has_no_benefit_until_it_is_delivered(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $reference = $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('order.reference');

        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $this->assertNull($order->benefit, 'an order that has not been delivered has no benefit yet');

        // And the panel says so rather than showing a zero.
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonPath('data.0.benefit', null);

        // Moving it to delivered settles it.
        $this->patchJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('order.benefit', 390);

        // 925 the customer paid (890 of goods and the 35 delivery), 500 of
        // goods, and the courier is paid 35 all the same: 390 remain.
        $this->assertSame(390.0, (float) $order->fresh('items')->benefit);
    }

    /**
     * The owner rectifies a purchase price, and the benefit of the orders
     * that contain the product follows the correction.
     */
    public function test_rectifying_a_purchase_price_rectifies_the_benefit(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame(780.0, (float) $order->fresh('items')->benefit);

        // The owner corrects the purchase price.
        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Oud Royale',
            'price' => 890,
            'cost_price' => 600,
            'stock' => 10,
        ])->assertOk();

        // And the benefit follows: 1815 of turnover, 1200 of goods, 35 of parcel.
        $this->assertSame(580.0, (float) $order->fresh('items')->benefit);
    }

    // ---------------------------------------------------------------
    // The delivery is a cost of the sale
    // ---------------------------------------------------------------

    /**
     * The courier is paid for the parcel whether or not the customer was
     * charged for the delivery. A free delivery is given out of the margin:
     * the benefit reads what is left after the parcel too.
     */
    public function test_the_delivery_is_a_cost_of_the_sale_even_when_it_is_free(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $order = $this->postJson('/api/admin/orders', $this->payload($product, [
            'shipping_cost' => 0,
            'remember_fee' => false,
        ]))->assertCreated()
            ->assertJsonPath('order.shipping_cost', 0)
            ->assertJsonPath('order.courier_cost', 35)
            ->assertJsonPath('order.cost', 1000)
            ->assertJsonPath('order.total', 1780)
            // 1780 the customer paid, 1000 of goods, the courier paid anyway.
            ->assertJsonPath('order.benefit', 745)
            ->json('order');

        $this->assertSame(745.0, (float) $order['benefit']);
    }

    // ---------------------------------------------------------------
    // The owner knows what the sums do not
    // ---------------------------------------------------------------

    /**
     * A benefit the owner set by hand is the benefit: it replaces the
     * calculation in the row, in the list and in the summary, rather than
     * bending it.
     */
    public function test_the_owner_can_set_the_benefit_by_hand(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $id = $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated()->json('order.id');

        // The calculation says 780; the owner knows the supplier gave them
        // another rate, and says the benefit is 900.
        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => 900])
            ->assertOk()
            ->assertJsonPath('order.benefit_override', '900.00')
            ->assertJsonPath('order.benefit', 900);

        // The list shows the owner's figure.
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonPath('data.0.benefit', 900);

        // And the summary counts it as what it replaced.
        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('summary.settled_orders', 1)
            ->assertJsonPath('summary.total_benefit', 900);
    }

    /**
     * A negative figure is a fact rather than a mistake: the sale can lose.
     */
    public function test_the_benefit_can_say_the_sale_lost(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $id = $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated()->json('order.id');

        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => -120])
            ->assertOk()
            ->assertJsonPath('order.benefit', -120);
    }

    /**
     * Taking the figure away gives the calculation back.
     */
    public function test_taking_the_figure_away_gives_the_calculation_back(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $id = $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated()->json('order.id');

        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => 900])->assertOk();
        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => null])
            ->assertOk()
            ->assertJsonPath('order.benefit_override', null)
            ->assertJsonPath('order.benefit', 780);
    }

    /**
     * An order still in flight has no benefit to correct: the sale has not
     * happened.
     */
    public function test_an_open_order_cannot_be_given_a_benefit(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $reference = $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('order.reference');
        $id = Order::query()->where('reference', $reference)->firstOrFail()->id;

        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => 500])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.errors.benefit_not_settled'));
    }

    /**
     * Only the owner may restate what the shop earned.
     */
    public function test_a_guest_cannot_set_a_benefit(): void
    {
        $product = $this->product(price: 890, cost: 500);

        $id = $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated()->json('order.id');

        // Somebody who is not the owner reads the panel, and tries to restate
        // what a sale earned.
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->patchJson('/api/admin/orders/'.$id.'/benefit', ['benefit' => 900])->assertForbidden();
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
        // The couriers were paid 35 twice, so 2740 - 1500 - 70 is earned.
        $response->assertJsonPath('summary.total_benefit', 1170);
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
