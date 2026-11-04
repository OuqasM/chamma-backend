<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customers the panel finds by phone number.
 *
 * The number is the identity. It is what the owner types to find a customer
 * again, and what keeps one person from being recorded several times because
 * they wrote their number in a different shape each time.
 *
 * These tests guard the three things that could quietly go wrong: a customer
 * written twice, a customer that cannot be found because the number was
 * compared as it was typed, and a number that is not Moroccan being filed
 * under a customer nobody will ever find.
 */
class AdminCustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(int $stock = 10): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => $stock,
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
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides);
    }

    private function recordOrder(Product $product, array $overrides = []): void
    {
        $this->postJson('/api/admin/orders', $this->payload($product, $overrides))
            ->assertCreated();
    }

    // ---------------------------------------------------------------
    // An order leaves a customer behind
    // ---------------------------------------------------------------

    public function test_recording_an_order_records_the_customer(): void
    {
        $product = $this->product();

        $this->recordOrder($product);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'name' => 'Amina Benali',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
        ]);
    }

    /**
     * One person, one row. A customer who orders twice — in two different
     * shapes of the same number — is still one customer, and the panel must
     * not offer the owner two of them.
     */
    public function test_the_same_customer_is_not_recorded_twice(): void
    {
        $product = $this->product();

        $this->recordOrder($product);
        $this->recordOrder($product, ['phone' => '06 12 34 56 78']);

        $this->assertDatabaseCount('customers', 1);

        // And the row was refreshed rather than duplicated.
        $this->assertSame(
            'Amina Benali',
            Customer::query()->firstOrFail()->name,
        );
    }

    /**
     * A customer who moved is at their new address now. Keeping the old one
     * would send a parcel somewhere they no longer live.
     */
    public function test_the_last_order_wins_on_the_address(): void
    {
        $product = $this->product();

        $this->recordOrder($product);
        $this->recordOrder($product, ['address' => '4 rue des Orangers']);

        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(
            '4 rue des Orangers',
            Customer::query()->firstOrFail()->address,
        );
    }

    /**
     * A refused order must not leave a customer behind either: the order and
     * the customer are written in the same transaction, so a stock failure
     * takes both back.
     */
    public function test_a_refused_order_records_no_customer(): void
    {
        $product = $this->product(stock: 1);

        $this->postJson('/api/admin/orders', $this->payload($product, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('customers', 0);
    }

    /**
     * A website order is a sale too, and the customer who made it is one the
     * panel should find when they ring. The number is stored as it was typed
     * there, and the normalised one is what identifies them.
     */
    public function test_a_checkout_order_records_the_customer_too(): void
    {
        $product = $this->product();

        $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseCount('customers', 1);

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('0612345678', $customer->phone);
        $this->assertSame('0612345678', $customer->phone_normalised);
    }

    // ---------------------------------------------------------------
    // The lookup
    // ---------------------------------------------------------------

    public function test_the_owner_finds_a_customer_by_their_number(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers/search?q=0612345678')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Benali')
            ->assertJsonPath('customers.0.city', 'CASABLANCA')
            ->assertJsonPath('customers.0.address', '12 rue Ibn Batouta');
    }

    /**
     * The number is typed from the left, so a partial one is a prefix: "0612"
     * means "somebody on 0612…".
     */
    public function test_a_partial_number_finds_the_customer(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers/search?q=0612')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Benali');
    }

    /**
     * A fragment, not only a prefix: the owner remembers the last digits or
     * the middle of a number more often than the whole thing, and the middle
     * is the part they are surest of.
     */
    public function test_a_fragment_of_the_number_finds_the_customer(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        foreach (['45678', '2345', '1234'] as $fragment) {
            $this->getJson('/api/admin/customers/search?q='.$fragment)
                ->assertOk()
                ->assertJsonCount(1, 'customers')
                ->assertJsonPath('customers.0.name', 'Amina Benali');
        }
    }

    /**
     * The number is stored in the national form, so a fragment typed with the
     * country code — or with the leading zero — finds the same customer.
     */
    public function test_a_fragment_is_found_whatever_shape_it_is_typed_in(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        foreach (['45678', '+212 6 12 34 56 78', '0612345678'] as $typed) {
            $this->getJson('/api/admin/customers/search?q='.urlencode($typed))
                ->assertOk()
                ->assertJsonCount(1, 'customers');
        }
    }

    /**
     * Too short to be a number yet, so nothing is searched: "61" would match
     * most of the shop's customers and help nobody.
     */
    public function test_a_number_that_is_too_short_finds_nothing(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers/search?q=06')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }

    public function test_an_unknown_number_finds_nothing(): void
    {
        $this->admin();

        $this->getJson('/api/admin/customers/search?q=0699999999')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }

    /**
     * The dropdown opens with rows in it: the customers the shop saw most
     * recently. A list that says "type to search" says nothing, and the owner
     * is usually recording an order for somebody they have dealt with before.
     */
    public function test_the_search_opens_with_the_customers_the_shop_knows(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers/search')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Benali');
    }

    /**
     * The term is a keyword, not only a number: a name finds the customer
     * too.
     */
    public function test_the_search_finds_a_customer_by_name(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers/search?q=Amina')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Benali');

        $this->getJson('/api/admin/customers/search?q=Youssef')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }

    /**
     * When the term is a whole number, that customer is the one the owner
     * meant: it comes first.
     */
    public function test_the_exact_number_comes_first(): void
    {
        $product = $this->product();

        $this->recordOrder($product, ['phone' => '0612345678', 'name' => 'Zineb Amrani']);
        $this->recordOrder($product, ['phone' => '0612345679', 'name' => 'Amina Benali']);

        $this->getJson('/api/admin/customers/search?q=0612345678')
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Zineb Amrani');
    }

    /**
     * The panel is the owner's own screen. A customer list is not public.
     */
    public function test_a_guest_cannot_look_up_the_customers(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/admin/customers/search?q=0612345678')->assertForbidden();
        $this->getJson('/api/admin/customers')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // The list the owner opens
    // ---------------------------------------------------------------

    /**
     * The customers are worth seeing on their own: who the shop sells to, how
     * many times they ordered, and when they last did.
     */
    public function test_the_customer_list_says_how_often_each_one_ordered(): void
    {
        $product = $this->product();

        $this->recordOrder($product);
        $this->recordOrder($product, ['address' => '4 rue des Orangers']);

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Benali')
            ->assertJsonPath('customers.0.orders_count', 2)
            ->assertJsonPath('customers.0.address', '4 rue des Orangers')
            // When they last ordered is the other half of the answer: a
            // regular from last week and somebody who called once in March are
            // not the same kind of customer.
            ->assertJsonPath('summary.total', 1)
            // Somebody who ordered twice is a regular.
            ->assertJsonPath('summary.repeat', 1);

        // The date is there, not null: `withMax` names the attribute after the
        // relation, and reading the wrong name gives every row a null date.
        $last = $this->getJson('/api/admin/customers')->json('customers.0.last_order_at');
        $this->assertNotNull($last);
    }

    /**
     * The owner knows the number, not the spelling: "0612345678" has to find
     * the customer whose number is stored in the reduced form.
     */
    public function test_the_customer_list_finds_them_by_the_number_as_it_is_spoken(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        $this->getJson('/api/admin/customers?search=0612345678')
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        $this->getJson('/api/admin/customers?search=0612')
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        // A fragment of the number, the way the owner remembers it.
        $this->getJson('/api/admin/customers?search=45678')
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        $this->getJson('/api/admin/customers?search=Amina')
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        $this->getJson('/api/admin/customers?search=Youssef')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }

    /**
     * One customer, and the orders they placed — which is the question the
     * list is really for.
     */
    public function test_a_customer_carries_their_orders(): void
    {
        $product = $this->product();

        $this->recordOrder($product);
        $this->recordOrder($product, ['address' => '4 rue des Orangers']);

        $id = Customer::query()->firstOrFail()->id;

        $this->getJson('/api/admin/customers/'.$id)
            ->assertOk()
            ->assertJsonPath('customer.name', 'Amina Benali')
            ->assertJsonPath('customer.orders_count', 2)
            ->assertJsonCount(2, 'orders')
            // A panel order is delivered, and it says so here too.
            ->assertJsonPath('orders.0.status', 'delivered');
    }

    /**
     * A website order keeps the number the customer typed, and it still
     * belongs to the customer: the orders are joined on the reduced number.
     */
    public function test_a_website_order_belongs_to_the_customer_too(): void
    {
        $product = $this->product();

        $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $id = Customer::query()->firstOrFail()->id;

        $this->getJson('/api/admin/customers/'.$id)
            ->assertOk()
            ->assertJsonPath('customer.orders_count', 1)
            ->assertJsonCount(1, 'orders');
    }

    // ---------------------------------------------------------------
    // The number itself
    // ---------------------------------------------------------------

    /**
     * A number that cannot be normalised cannot identify a customer, so it is
     * refused with the one sentence that says what a usable number looks like.
     */
    public function test_a_number_that_is_not_moroccan_is_refused(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['phone' => '+33 6 12 34 56 78']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    /**
     * A landline is a Moroccan number too, and some customers order on one.
     */
    public function test_a_landline_is_accepted(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->payload($product, ['phone' => '0512345678']))
            ->assertCreated()
            ->assertJsonPath('order.customer.phone', '0512345678');
    }

    // ---------------------------------------------------------------
    // The orders the shop already had
    // ---------------------------------------------------------------

    /**
     * Runs the backfill migration's `up()`, the way `php artisan migrate`
     * would.
     */
    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_11_04_110000_backfill_customers_from_orders.php'
        );

        $migration->up();
    }

    /**
     * The shop's orders predate the customer table: they were sales, but there
     * was no record of who they were sold to. So the customer list would have
     * started empty while the order list was full — the "we have no customers"
     * reading that is wrong.
     */
    public function test_the_orders_the_shop_already_had_become_customers(): void
    {
        $product = $this->product();
        $this->recordOrder($product);

        // The state before the backfill: an order, and no customer record.
        Customer::query()->delete();
        $this->assertDatabaseCount('customers', 0);

        $this->runBackfill();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'name' => 'Amina Benali',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'phone_normalised' => '0612345678',
        ]);
    }

    /**
     * The city is written in the carrier's own spelling, which is what the
     * panel's city list holds. An order written with the readable spelling
     * ("Rabat") would leave a customer whose city the form cannot prefill —
     * the save would then be refused for a field the owner cannot see.
     */
    public function test_the_backfilled_city_is_the_carriers_spelling(): void
    {
        // Written straight to the table, the way the shop's older records
        // are: the order API only accepts the carrier's spelling, so an order
        // with the readable one cannot be made through it.
        Order::query()->create([
            'reference' => 'CHM-1049',
            'locale' => 'fr',
            'first_name' => 'Youssef',
            'last_name' => 'Alaoui',
            'customer_name' => 'Youssef Alaoui',
            'phone' => '0661876543',
            'phone_normalised' => '+212661876543',
            'city' => 'Rabat',
            'address' => '12 rue de Test',
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'subtotal' => 100,
            'shipping_cost' => 35,
            'total' => 135,
            'status' => OrderStatus::Delivered,
        ]);

        Customer::query()->delete();
        $this->runBackfill();

        $this->assertSame('RABAT', Customer::query()->firstOrFail()->city);
    }

    /**
     * One person, one customer — however many orders they placed and however
     * they wrote their number each time.
     */
    public function test_one_person_with_several_orders_is_one_customer(): void
    {
        $product = $this->product();

        $this->recordOrder($product);
        $this->recordOrder($product, ['phone' => '06 12 34 56 78']);
        $this->recordOrder($product, ['phone' => '+212612345678', 'address' => '4 rue des Orangers']);

        Customer::query()->delete();
        $this->runBackfill();

        $this->assertDatabaseCount('customers', 1);

        // And the last order wins on the address, as it does for a new order.
        $this->assertSame(
            '4 rue des Orangers',
            Customer::query()->firstOrFail()->address,
        );

        // The backfilled customer carries every one of those orders.
        $this->assertSame(3, Customer::query()->firstOrFail()->orders()->count());
    }
}
