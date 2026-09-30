<?php

namespace Tests\Feature;

use App\Mail\NewOrderNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Orders typed in by the owner.
 *
 * The point of the feature is that a phone order is an order. It is created
 * through the same service as a checkout order, in the same table, and shows up
 * in the same list. These tests guard the parts where that could quietly go
 * wrong: totals and stock being set by whatever was typed into the panel, the
 * order arriving in a state the rest of the panel does not understand, and the
 * "new order" email firing for something the owner just did themselves.
 */
class AdminOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingService
    {
        return app(SettingService::class);
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(string $name = 'Oud Royale', int $stock = 10, float $price = 890.0): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', [
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ])->assertCreated();

        return Product::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     *
     * `CASABLANCA` is upper case because that is how the carrier writes it in
     * config/shipping_zones.php, and checkout validates the city against that
     * exact list. It is not a typo to be tidied up: the storefront only ever
     * sends what the city <select> gives it.
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
    // It records an order
    // ---------------------------------------------------------------

    public function test_the_owner_can_record_an_order(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product))
            ->assertCreated()
            ->assertJsonPath('order.customer.name', 'Amina Benali')
            ->assertJsonPath('order.customer.phone', '0612345678');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Amina Benali',
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    }

    /**
     * A phone order is an order like any other: same table, same list, same
     * detail page. A second table would mean the owner had to remember which
     * one a customer landed in.
     */
    public function test_a_typed_order_appears_in_the_same_list_as_a_checkout_order(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Amina Benali')
            ->assertJsonPath('data.0.items.0.quantity', 2);

        // And the search the owner uses to find it works on a typed order.
        $this->getJson('/api/admin/orders?search=Amina')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---------------------------------------------------------------
    // The panel does not get to set the numbers
    // ---------------------------------------------------------------

    /**
     * Price is read from the database, not from the request. A panel that could
     * send its own price would be a way to charge whatever the form said, which
     * is the one thing a storefront price list exists to prevent.
     */
    public function test_the_price_comes_from_the_database_not_the_request(): void
    {
        $product = $this->product(stock: 10, price: 890.0);

        $response = $this->postJson('/api/admin/orders', $this->payload($product, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1, 'price' => 1],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('order.items.0.unit_price', 890);
        $response->assertJsonPath('order.subtotal', 1780);
        // 1780 of goods plus the CASABLANCA delivery fee. Both are computed from
        // the database, which is the point: the request supplied neither.
        $response->assertJsonPath('order.shipping_cost', 35);
        $response->assertJsonPath('order.total', 1815);
    }

    public function test_stock_is_decremented(): void
    {
        $product = $this->product(stock: 10);

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        $this->assertSame(8, $product->fresh()->stock);
    }

    /**
     * The owner can be wrong about how much is on the shelf. The answer is a
     * message naming the product, not a half-written order.
     */
    public function test_it_refuses_to_oversell(): void
    {
        $product = $this->product(stock: 1);

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->fresh()->stock, 'stock must be untouched by a refused order');
    }

    /**
     * A failed order must not leave the next order short a unit: the transaction
     * has to roll back the decrement together with the order.
     */
    public function test_a_refused_order_rolls_back_the_whole_batch(): void
    {
        $good = $this->product('Oud Royale', stock: 5);
        $thin = $this->product('Musc Blanc', stock: 1);

        $this->postJson('/api/admin/orders', $this->payload($good, [
            'items' => [
                ['product_id' => $good->id, 'quantity' => 2],
                ['product_id' => $thin->id, 'quantity' => 9],
            ],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $good->fresh()->stock, 'the first line must not stay deducted');
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_it_requires_a_name(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * A city is not free text. It decides the shipping fee, and a misspelled
     * city would silently quote the wrong one — or none at all.
     */
    public function test_it_refuses_a_city_the_store_does_not_deliver_to(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['city' => 'NOWHEREVILLE']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_it_refuses_an_unknown_payment_method(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['payment_method' => 'bitcoin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_it_needs_at_least_one_item(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['items' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Only the owner can do this. The storefront signup route is open to anyone,
     * and this one must not be.
     */
    public function test_a_guest_cannot_record_an_order(): void
    {
        $product = $this->product();
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/api/admin/orders', $this->payload($product))->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
    }

    // ---------------------------------------------------------------
    // It behaves like the rest of the panel
    // ---------------------------------------------------------------

    /**
     * Cash in hand, or a transfer already cleared. Worth recording at the moment
     * of writing the order, because there is no later prompt to remember it by.
     */
    public function test_it_can_be_marked_as_already_paid(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'payment_status' => 'paid',
        ]))->assertCreated()->assertJsonPath('order.payment_status', 'paid');
    }

    /**
     * The order's language decides which product name is snapshotted onto the
     * line, so a customer served in Arabic gets an Arabic order.
     */
    public function test_it_snapshots_product_names_in_the_chosen_language(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['locale' => 'ar']))
            ->assertCreated();

        $this->assertSame('ar', Order::query()->firstOrFail()->locale);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['locale' => 'kl']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    /**
     * The whole point of the "no email" decision.
     *
     * The order alert exists to tell the owner something happened while they
     * were elsewhere. Here they are the one who happened, and a "new order"
     * email arriving seconds after they typed it reads as an order they did not
     * take. Checkout still sends one; this path must not.
     */
    public function test_recording_an_order_sends_no_order_email(): void
    {
        $product = $this->product();

        $this->settings()->setMany([
            SettingService::ORDER_NOTIFICATION_EMAILS => 'owner@example.com',
            SettingService::ORDER_NOTIFICATION_ENABLED => '1',
        ]);

        Mail::fake();

        $this->postJson('/api/admin/orders', $this->payload($product))->assertCreated();

        Mail::assertNothingSent();
    }

    /**
     * The counterpart, so the assertion above is not passing for the wrong
     * reason: alerts disabled by configuration is a different thing from
     * "this endpoint never notifies", and the two must not be confused later.
     */
    public function test_checkout_still_sends_the_order_email(): void
    {
        $product = $this->product();

        $this->settings()->setMany([
            SettingService::ORDER_NOTIFICATION_EMAILS => 'owner@example.com',
            SettingService::ORDER_NOTIFICATION_ENABLED => '1',
        ]);

        Mail::fake();

        // Flat, like the real storefront body: checkout takes the customer
        // fields at the top level, not nested under a `customer` key.
        $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        Mail::assertSent(NewOrderNotification::class);
    }
}
