<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Tests\TestCase;

/**
 * A cart that is never checked out has to be visible to the shop.
 *
 * The storefront cart lives in `localStorage`, so until an order is placed it
 * exists nowhere on the server. These cover the seam that changes that: a sync
 * records what the browser is holding, keyed on the same anonymous cookie the
 * visitor tracker issues, and a checkout closes the loop by marking that same
 * cart converted.
 *
 * The pricing assertions matter as much as the row-count ones. A client that
 * could name its own prices would make every total in the report fiction, and a
 * client that could name its own products would make the report a list of
 * things nobody looked at.
 */
class CartTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 32-char lowercase hex token, the format the tracker issues and the only
     * shape `resolveVisitorId` accepts.
     */
    private const VISITOR = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(array $overrides = []): Product
    {
        $response = $this->postJson('/api/admin/products', array_merge([
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
        ], $overrides));

        $response->assertCreated();

        return Product::findOrFail($response->json('product.id'));
    }

    /**
     * @param  array<int, array{product_id: int, quantity?: int}>  $items
     */
    private function sync(array $items, string $visitor = self::VISITOR)
    {
        return $this->asShopfront()
            ->withUnencryptedCookie('chamma_visitor', $visitor)
            ->postJson('/api/fr/cart', ['items' => $items]);
    }

    /**
     * Marks the request as coming from the storefront origin.
     *
     * Not optional decoration. `statefulApi()` puts `EncryptCookies` in the API
     * stack, and the `Origin`/`Referer` pair is what activates that stateful
     * path — without it the identity cookie is dropped on the floor and every
     * request mints a brand new token. That is not a test-only quirk: it is the
     * same condition under which a real shopper's cookie is or is not sent, and
     * getting it wrong here would make the tests pass while every cart in
     * production arrived unrecognised.
     */
    private function asShopfront(): static
    {
        return $this->withCredentials();
    }

    public function test_it_records_what_the_browser_is_holding(): void
    {
        $this->admin();
        $oud = $this->createProduct(['name' => 'Oud Royale', 'price' => 890]);
        $musk = $this->createProduct(['name' => 'Musc Blanc', 'price' => 340]);

        $this->sync([
            ['product_id' => $oud->id, 'quantity' => 2],
            ['product_id' => $musk->id, 'quantity' => 1],
        ])->assertNoContent();

        $cart = Cart::query()->sole();

        $this->assertSame(self::VISITOR, $cart->visitor_id, 'keyed on the cookie token, not the product or the session');
        $this->assertSame('fr', $cart->locale);
        $this->assertSame(3, $cart->items_count, 'units, not distinct products');
        $this->assertNull($cart->converted_at);
        // 890*2 + 340 = 2120
        $this->assertSame('2120.00', $cart->subtotal);
    }

    public function test_it_prices_lines_from_the_database_not_the_request(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 890]);

        // A client claiming the perfume costs 1 DH. The recorded line must be the
        // database's 890, or every total in the report is whatever the client felt
        // like sending.
        $this->sync([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1]])
            ->assertNoContent();

        $item = Cart::query()->sole()->items()->sole();

        $this->assertSame('890.00', $item->unit_price);
        $this->assertSame('890.00', $item->subtotal);
    }

    public function test_a_second_sync_replaces_the_previous_contents(): void
    {
        $this->admin();
        $oud = $this->createProduct(['name' => 'Oud Royale', 'price' => 890]);
        $musk = $this->createProduct(['name' => 'Musc Blanc', 'price' => 340]);

        $this->sync([
            ['product_id' => $oud->id, 'quantity' => 1],
            ['product_id' => $musk->id, 'quantity' => 1],
        ])->assertNoContent();

        // The shopper removes the oud. The cart is replaced wholesale, so the musc
        // line must not survive alongside it — a report saying "left 2 items" when
        // they left 1 is worse than no report.
        $this->sync([['product_id' => $musk->id, 'quantity' => 3]])->assertNoContent();

        $cart = Cart::query()->sole();

        $this->assertSame(3, $cart->items_count, 'units, so three of one perfume reads as three items left');
        $this->assertSame('1020.00', $cart->subtotal);
        $this->assertSame(
            ['Musc Blanc'],
            $cart->items()->pluck('product_name')->all(),
            'the removed line is gone, not duplicated'
        );
    }

    public function test_an_emptied_cart_is_recorded_as_empty(): void
    {
        $this->admin();
        $product = $this->createProduct();

        $this->sync([['product_id' => $product->id, 'quantity' => 1]])->assertNoContent();
        $this->sync([])->assertNoContent();

        $cart = Cart::query()->sole();

        $this->assertSame(0, $cart->items_count);
        $this->assertSame('0.00', $cart->subtotal);
        // A cart being cleared is a change worth counting, and it is the state
        // most likely to be followed by a shopper who never returns.
        $this->assertSame(2, $cart->updates_count);
    }

    public function test_the_same_cookie_is_one_cart_across_requests(): void
    {
        $this->admin();
        $product = $this->createProduct();

        $this->sync([['product_id' => $product->id, 'quantity' => 1]])->assertNoContent();
        $this->sync([['product_id' => $product->id, 'quantity' => 2]])->assertNoContent();

        $this->assertSame(1, Cart::query()->count(), 'one shopper is one cart, not a cart per request');
    }

    public function test_a_client_with_no_cookie_is_issued_one(): void
    {
        $this->admin();
        $product = $this->createProduct();

        $response = $this->asShopfront()->postJson('/api/fr/cart', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertNoContent();

        $cookie = collect($response->headers->getCookies())
            ->first(fn (SymfonyCookie $c) => $c->getName() === 'chamma_visitor');

        $this->assertNotNull($cookie, 'a first-ever request still gets a stable token');
        $this->assertTrue($cookie->isHttpOnly(), 'nothing in the browser needs to read it');
        // A cart sync can be the browser's first request, so without this cookie
        // the next sync would mint a different token and orphan this cart.
        $this->assertSame(
            $cookie->getValue(),
            Cart::query()->sole()->visitor_id,
            'the cart is keyed on the token it just handed out'
        );
    }

    public function test_a_malformed_cookie_gets_a_fresh_token_rather_than_being_rejected(): void
    {
        $this->admin();
        $product = $this->createProduct();

        $response = $this->asShopfront()->withUnencryptedCookie('chamma_visitor', 'not-a-valid-token')
            ->postJson('/api/fr/cart', ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);

        $response->assertNoContent();

        $this->assertNotSame('not-a-valid-token', Cart::query()->sole()->visitor_id);
    }

    public function test_a_duplicate_product_line_collapses_into_one(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 100]);

        // A stale client sending the same perfume twice records one line of two,
        // not two lines — the cart the shopper sees, not a doubled one.
        $this->sync([
            ['product_id' => $product->id, 'quantity' => 1],
            ['product_id' => $product->id, 'quantity' => 1],
        ])->assertNoContent();

        $cart = Cart::query()->sole();

        $this->assertSame(1, $cart->items()->count(), 'one line, not two');
        $this->assertSame(2, $cart->items()->sole()->quantity, 'with the quantities added');
        $this->assertSame(2, $cart->items_count, 'units, so the two survive the merge');
        $this->assertSame('200.00', $cart->subtotal);
    }

    public function test_a_product_that_has_gone_inactive_is_dropped_not_rejected(): void
    {
        $this->admin();
        $gone = $this->createProduct(['name' => 'Retiré', 'price' => 100]);
        $kept = $this->createProduct(['name' => 'Encore', 'price' => 250]);

        $gone->update(['is_active' => false]);

        $this->sync([
            ['product_id' => $gone->id, 'quantity' => 1],
            ['product_id' => $kept->id, 'quantity' => 1],
        ])->assertNoContent();

        $cart = Cart::query()->sole();

        $this->assertSame(['Encore'], $cart->items()->pluck('product_name')->all());
        $this->assertSame('250.00', $cart->subtotal);
    }

    public function test_a_product_that_does_not_exist_is_dropped_rather_than_stored(): void
    {
        $this->admin();
        $real = $this->createProduct(['price' => 250]);

        $this->sync([
            ['product_id' => 99999, 'quantity' => 1],
            ['product_id' => $real->id, 'quantity' => 1],
        ])->assertNoContent();

        $this->assertSame(['Oud Royale'], Cart::query()->sole()->items()->pluck('product_name')->all());
    }

    public function test_quantity_is_capped(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 10]);

        $this->sync([['product_id' => $product->id, 'quantity' => 100000]])->assertNoContent();

        $this->assertSame(99, Cart::query()->sole()->items()->sole()->quantity);
    }

    public function test_the_price_is_a_snapshot_so_repricing_does_not_rewrite_history(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 890]);

        $this->sync([['product_id' => $product->id, 'quantity' => 1]])->assertNoContent();

        // The shop re-prices the perfume next month. A cart from last Tuesday must
        // still say what it cost last Tuesday, or the report answers "what did
        // they leave" with "what does it cost now".
        $product->update(['price' => 1200]);

        $cart = Cart::query()->sole();

        $this->assertSame('890.00', $cart->items()->sole()->unit_price);
        $this->assertSame('890.00', $cart->subtotal);
    }

    public function test_the_locale_the_cart_was_filled_in_is_recorded(): void
    {
        $this->admin();
        $product = $this->createProduct(['name' => 'Oud Royale', 'price' => 890]);

        $this->asShopfront()->withUnencryptedCookie('chamma_visitor', self::VISITOR)
            ->postJson('/api/ar/cart', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertNoContent();

        $cart = Cart::query()->sole();

        // Taken from the route, not from the request body, so a client cannot
        // file an Arabic cart as French and have the report mislabel it.
        $this->assertSame('ar', $cart->locale);

        // The name is the product's single name. Product *names* are stored once
        // in this schema — only descriptions are per-locale — so the snapshot is
        // the same in all three languages, and asserting an Arabic name here
        // would be asserting a translation feature that does not exist.
        $this->assertSame('Oud Royale', $cart->items()->sole()->product_name);
    }

    public function test_a_malformed_payload_is_a_422(): void
    {
        $this->sync([['product_id' => 'abc']])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->sync([['product_id' => 1, 'quantity' => 0]])->assertStatus(422);

        $this->assertSame(0, Cart::query()->count(), 'a rejected payload writes nothing');
    }

    public function test_a_sync_failure_does_not_become_an_error_response(): void
    {
        $this->admin();
        $product = $this->createProduct();

        // A payload that passes validation but points the service at a table that
        // is not there. The shopper's cart is in localStorage and their basket
        // works regardless, so a lost report row must not surface as a failure on
        // a page — or a quantity change becomes a failed request.
        Schema::drop('cart_items');

        $this->sync([['product_id' => $product->id, 'quantity' => 1]])->assertNoContent();
    }

    public function test_checkout_marks_the_cart_converted_and_links_the_order(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 890, 'stock' => 10]);

        $this->sync([['product_id' => $product->id, 'quantity' => 2]])->assertNoContent();

        $this->asShopfront()->withUnencryptedCookie('chamma_visitor', self::VISITOR)
            ->postJson('/api/fr/checkout', $this->checkoutPayload($product, 2))
            ->assertCreated();

        $cart = Cart::query()->sole();
        $order = Order::query()->sole();

        $this->assertNotNull($cart->converted_at, 'the cart no longer reads as abandoned');
        $this->assertSame($order->id, $cart->order_id);
    }

    public function test_checkout_without_a_prior_sync_still_records_a_cart(): void
    {
        $this->admin();
        $product = $this->createProduct(['price' => 890, 'stock' => 10]);

        // Someone who never synced — a stale localStorage, a saved checkout link.
        // The order is not the thing that must not be lost to a report row, so it
        // still succeeds and the conversion rate is not understated.
        $this->postJson('/api/fr/checkout', $this->checkoutPayload($product, 1))->assertCreated();

        $cart = Cart::query()->sole();

        $this->assertNotNull($cart->converted_at);
        $this->assertSame(Order::query()->sole()->id, $cart->order_id);
    }

    public function test_one_shopper_buying_twice_is_one_converted_cart(): void
    {
        $this->admin();
        $first = $this->createProduct(['name' => 'Oud Royale', 'price' => 890, 'stock' => 20]);
        $second = $this->createProduct(['name' => 'Musc Blanc', 'price' => 340, 'stock' => 20]);

        $this->sync([['product_id' => $first->id, 'quantity' => 1]])->assertNoContent();

        $this->asShopfront()->withUnencryptedCookie('chamma_visitor', self::VISITOR)
            ->postJson('/api/fr/checkout', $this->checkoutPayload($first, 1))->assertCreated();
        $this->asShopfront()->withUnencryptedCookie('chamma_visitor', self::VISITOR)
            ->postJson('/api/fr/checkout', $this->checkoutPayload($second, 1))->assertCreated();

        // Two orders, one shopper. A second cart row would count one person as
        // two carts and halve the apparent conversion rate.
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(1, Cart::query()->count());
    }

    public function test_an_order_placed_in_the_panel_does_not_create_a_cart(): void
    {
        // A typed-in order has no shopper, so there is no identity to key on and
        // no cart to write. The signature makes the parameter optional for
        // exactly this caller.
        $this->admin();
        $product = $this->createProduct(['price' => 890, 'stock' => 10]);

        $this->postJson('/api/admin/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'name' => 'Walk In',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
        ])->assertCreated();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Cart::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(Product $product, int $quantity): array
    {
        return [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'name' => 'Yasmine Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
        ];
    }
}
