<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `carts:purge`.
 *
 * Cart rows accumulate without anybody clicking anything, and unlike a product
 * they are not content the shop manages — they are a record of what a stranger
 * was about to buy. That makes two things worth proving: that retention actually
 * happens on its own, and that it is bounded. A tracker with no deletion path is
 * a tracker that grows forever, which is not a defensible thing to ship.
 *
 * The other half is care: a cart that became an order is the one row with a
 * reason to exist beyond reporting, so it is kept unless deletion is asked for
 * explicitly.
 */
class PurgeCartsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cart(array $overrides = []): Cart
    {
        $at = $overrides['updated_at'] ?? now()->subDays(60);

        $cart = Cart::query()->create(array_merge([
            'visitor_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'locale' => 'fr',
            'subtotal' => 1780,
            'items_count' => 2,
            'updates_count' => 1,
            'converted_at' => null,
        ], $overrides));

        $cart->items()->create([
            'product_id' => 1,
            'product_name' => 'Oud Royale',
            'product_slug' => 'oud-royale',
            'product_sku' => null,
            'product_image' => null,
            'unit_price' => 890,
            'quantity' => 2,
            'subtotal' => 1780,
        ]);

        Cart::query()->whereKey($cart->getKey())->update(['created_at' => $at, 'updated_at' => $at]);

        return $cart->refresh();
    }

    public function test_it_deletes_an_old_unconverted_cart(): void
    {
        $this->cart();

        $this->artisan('carts:purge')->assertSuccessful();

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_it_keeps_a_recent_cart(): void
    {
        // Retention is a floor on age, not a schedule. A cart from last week is
        // still worth reporting on.
        $this->cart(['visitor_id' => str_repeat('b', 32), 'updated_at' => now()->subDays(2)]);

        $this->artisan('carts:purge')->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
    }

    public function test_it_keeps_a_converted_cart_by_default(): void
    {
        // This row is the one record of what the visitor intended, tied to an
        // order. Deleting it by default would quietly destroy the link between a
        // sale and the basket that produced it.
        $cart = $this->cart(['converted_at' => now()->subDays(60)]);

        $this->artisan('carts:purge')->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
        $this->assertNotNull(Cart::query()->find($cart->id));
    }

    public function test_it_deletes_converted_carts_when_asked(): void
    {
        $this->cart(['converted_at' => now()->subDays(60)]);
        $this->cart(['visitor_id' => str_repeat('b', 32), 'converted_at' => now()->subDays(61)]);

        $this->artisan('carts:purge', ['--include-converted' => true])->assertSuccessful();

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        // The point of a dry run is to be run against production first, so it has
        // to actually change nothing.
        $this->cart();
        $this->cart(['visitor_id' => str_repeat('b', 32), 'subtotal' => 900]);

        $this->artisan('carts:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, Cart::query()->count());
    }

    public function test_it_deletes_the_lines_with_the_cart(): void
    {
        $this->cart();

        $this->assertSame(1, CartItem::query()->count());

        $this->artisan('carts:purge')->assertSuccessful();

        // Orphaned lines would keep the products alive in a table nothing reads.
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_it_honours_a_custom_window(): void
    {
        $old = $this->cart(['visitor_id' => str_repeat('b', 32), 'updated_at' => now()->subDays(40)]);
        $newer = $this->cart(['visitor_id' => str_repeat('c', 32), 'updated_at' => now()->subDays(8)]);

        $this->artisan('carts:purge', ['--days' => 30])->assertSuccessful();

        $this->assertNull(Cart::query()->find($old->id));
        $this->assertNotNull(Cart::query()->find($newer->id));
    }

    public function test_a_window_of_zero_does_not_purge_everything(): void
    {
        // `--days=0` would mean "older than now", i.e. every cart ever written.
        // Clamped to one day, so a mistyped argument cannot empty the table.
        $this->cart(['updated_at' => now()->subHours(2)]);
        $this->cart(['visitor_id' => str_repeat('b', 32), 'updated_at' => now()->subDays(5)]);

        $this->artisan('carts:purge', ['--days' => 0])->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
    }

    public function test_a_negative_window_does_not_purge_everything_either(): void
    {
        $this->cart(['updated_at' => now()->subHours(2)]);

        $this->artisan('carts:purge', ['--days' => -5])->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
    }

    public function test_it_is_a_no_op_when_there_is_nothing_to_purge(): void
    {
        $this->cart(['updated_at' => now()->subDay()]);

        $this->artisan('carts:purge')->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
    }

    public function test_it_says_what_the_purge_would_have_removed(): void
    {
        // The reason to run it is knowing what is being thrown away, so the
        // numbers have to be counted before the delete rather than after.
        $this->cart();
        $this->cart(['visitor_id' => str_repeat('b', 32), 'subtotal' => 900, 'items_count' => 1]);

        $this->artisan('carts:purge', ['--dry-run' => true])
            ->expectsOutputToContain('2')
            ->assertSuccessful();
    }
}
