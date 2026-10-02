<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The abandoned-cart report in the admin panel.
 *
 * This list is the reason the tracking exists: an owner needs to see the baskets
 * that were filled and left, and what they were worth. So the tests concentrate
 * on the answers that would make that report quietly wrong — counting a cart
 * that is still being filled as lost, counting it as a failed conversion, and
 * reporting a value that does not match what was left behind.
 *
 * The gate on access matters here too. A cart is a record of what an
 * unidentified person was about to buy; it is readable by an admin and nobody
 * else.
 */
class AdminCartTest extends TestCase
{
    use RefreshDatabase;

    private const VISITOR = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function actingAsAdmin()
    {
        return $this->actingAs(
            User::factory()->create(['is_admin' => true])
        );
    }

    /**
     * A cart written straight to the table, placed at a chosen age.
     *
     * Going through the endpoint would date every cart by `now()`, and the whole
     * report is about how old a cart is, so the tests need to say when it was
     * last touched. Pass `last_active_at` for that; `created_at` and
     * `updated_at` follow, because the report reads the age off `updated_at`.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cart(array $overrides = []): Cart
    {
        $at = $overrides['last_active_at'] ?? now()->subDays(2);
        unset($overrides['last_active_at']);

        $cart = Cart::query()->create(array_merge([
            'visitor_id' => self::VISITOR,
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
            'product_sku' => 'OUD-1',
            'product_image' => null,
            'unit_price' => 890,
            'quantity' => 2,
            'subtotal' => 1780,
        ]);

        // `create()` stamps `updated_at` with now(), so a cart the test means to
        // be two days old has to be backdated explicitly or the idle window
        // sees it as brand new.
        Cart::query()->whereKey($cart->getKey())->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $cart->refresh();
    }

    public function test_the_cart_list_requires_authentication(): void
    {
        $this->getJson('/api/admin/carts')->assertUnauthorized();
    }

    public function test_a_signed_in_non_admin_cannot_read_carts(): void
    {
        // The visitor route group alone is not authorisation. Being logged in as
        // a shop's own staff account must not be enough to read other people's
        // baskets.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->getJson('/api/admin/carts')
            ->assertForbidden();
    }

    public function test_it_lists_a_cart_that_was_filled_and_left(): void
    {
        $this->cart();

        $carts = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('carts');

        $this->assertCount(1, $carts);
        $this->assertSame('abandoned', $carts[0]['status']);
        $this->assertSame('Oud Royale', $carts[0]['items'][0]['product_name']);
        $this->assertSame(1780.0, (float) $carts[0]['subtotal']);
    }

    public function test_a_cart_still_being_filled_is_not_reported_as_abandoned(): void
    {
        // The distinction the idle window exists for: someone reading this report
        // right now may have their own basket open in another tab, and telling
        // the owner they lost a sale that is still in progress is worse than
        // saying nothing.
        $this->cart(['last_active_at' => now()->subMinutes(2)]);
        $this->cart(['visitor_id' => str_repeat('b', 32), 'last_active_at' => now()->subDays(3)]);

        $response = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk();

        $this->assertCount(1, $response->json('carts'));
        $this->assertSame(1, $response->json('summary.abandoned'));
        $this->assertSame(1, $response->json('summary.in_progress'));
    }

    public function test_the_conversion_rate_leaves_out_carts_still_in_progress(): void
    {
        // A shopper browsing right now has not failed to convert. Including
        // their cart in the denominator would make the rate fall every time
        // somebody opened the site.
        $this->cart(['visitor_id' => str_repeat('b', 32), 'last_active_at' => now()->subDays(3)]);
        $this->cart([
            'visitor_id' => str_repeat('c', 32),
            'converted_at' => now()->subDay(),
            'last_active_at' => now()->subDays(1),
        ]);
        $this->cart(['visitor_id' => str_repeat('d', 32), 'last_active_at' => now()->subMinutes(1)]);

        $summary = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('summary');

        // One converted, one abandoned: 50%, not one-in-three.
        $this->assertSame(50.0, (float) $summary['conversion_rate']);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['in_progress']);
    }

    public function test_the_value_left_behind_is_the_value_of_the_abandoned_carts(): void
    {
        $this->cart(['visitor_id' => str_repeat('b', 32), 'subtotal' => 1780, 'last_active_at' => now()->subDays(2)]);
        $this->cart([
            'visitor_id' => str_repeat('c', 32),
            'subtotal' => 900,
            'converted_at' => now()->subDay(),
        ]);
        $this->cart(['visitor_id' => str_repeat('d', 32), 'subtotal' => 500, 'last_active_at' => now()->subMinutes(1)]);

        $summary = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('summary');

        // Only the abandoned one. Counting a sale as lost money, or an open
        // basket as money already banked, both misdirect the owner.
        $this->assertSame(1780.0, (float) $summary['abandoned_value']);
        $this->assertSame(900.0, (float) $summary['converted_value']);
    }

    public function test_it_filters_by_status(): void
    {
        $abandoned = $this->cart(['visitor_id' => str_repeat('b', 32), 'last_active_at' => now()->subDays(3)]);
        $open = $this->cart(['visitor_id' => str_repeat('c', 32), 'last_active_at' => now()->subMinutes(1)]);
        $converted = $this->cart(['visitor_id' => str_repeat('d', 32), 'converted_at' => now()->subDay()]);

        $open_ids = collect($this->actingAsAdmin()->getJson('/api/admin/carts?status=open')->json('carts'))
            ->pluck('id')->all();
        $converted_ids = collect($this->actingAsAdmin()->getJson('/api/admin/carts?status=converted')->json('carts'))
            ->pluck('id')->all();

        $this->assertSame([$open->id], $open_ids);
        $this->assertSame([$converted->id], $converted_ids);
        $this->assertNotContains($abandoned->id, $open_ids);
    }

    public function test_an_unknown_status_is_rejected_rather_than_ignored(): void
    {
        // A typo must not quietly become "show me every cart in the database",
        // carts being filled right now included. The filter is an explicit set,
        // so a value outside it is a client error and is answered as one.
        $this->cart(['visitor_id' => str_repeat('b', 32), 'last_active_at' => now()->subDays(3)]);
        $this->cart(['visitor_id' => str_repeat('c', 32), 'last_active_at' => now()->subMinutes(1)]);

        $this->actingAsAdmin()
            ->getJson('/api/admin/carts?status=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_it_shows_the_address_behind_a_cart_without_storing_it_on_the_cart(): void
    {
        // The IP lives on the visit row, not on the cart. A cart that is synced
        // before the shopper has loaded a page has no visit yet, and must still
        // be listed rather than dropped by the join.
        Visit::query()->create([
            'visitor_id' => self::VISITOR,
            'ip' => '203.0.113.7',
            'device' => 'mobile',
            'browser' => 'Safari',
            'os' => 'iOS',
            'path' => 'products',
            'referrer' => null,
            'locale' => 'fr',
            'visits_count' => 3,
            'first_seen' => now()->subDays(4),
            'last_seen' => now()->subHours(2),
        ]);

        $this->cart();

        $with_visit = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('carts');
        $this->assertSame('203.0.113.7', $with_visit[0]['ip']);

        // The same cart, whose visitor has no visit row: still listed, no IP.
        Visit::query()->where('visitor_id', self::VISITOR)->delete();
        $without_visit = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('carts');
        $this->assertCount(1, $without_visit);
        $this->assertNull($without_visit[0]['ip']);
    }

    public function test_it_carries_the_visitor_token_so_a_cart_can_be_matched_to_a_visit(): void
    {
        // The token is the honest identity here and it is what lets the owner
        // reconcile "someone left a 1,780 DH basket" with a row in the visitor
        // list. Hiding it would leave the report unable to answer its own
        // question.
        $this->cart();

        $row = $this->actingAsAdmin()->getJson('/api/admin/carts')->json('carts.0');

        $this->assertSame(self::VISITOR, $row['visitor_id']);
    }

    public function test_it_groups_the_most_often_abandoned_products(): void
    {
        // The single cart on its own says little; the same product left in four
        // baskets is a stock, price or description problem the owner can act on.
        foreach (['b', 'c', 'd', 'e'] as $index => $letter) {
            $this->cart([
                'visitor_id' => str_repeat($letter, 32),
                'last_active_at' => now()->subDays($index + 2),
            ]);
        }

        $top = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('summary.top_abandoned');

        $this->assertSame('Oud Royale', $top[0]['name']);
        $this->assertSame(4, (int) $top[0]['carts']);
        $this->assertSame(8, (int) $top[0]['units']);
    }

    public function test_it_ignores_converted_carts_when_grouping_products(): void
    {
        // Someone who bought it is not evidence against the product.
        $this->cart(['visitor_id' => str_repeat('b', 32), 'last_active_at' => now()->subDays(2)]);
        $this->cart(['visitor_id' => str_repeat('c', 32), 'converted_at' => now()->subDay()]);

        $top = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk()->json('summary.top_abandoned');

        $this->assertSame(1, (int) $top[0]['carts']);
    }

    public function test_it_returns_nothing_rather_than_failing_when_no_cart_matches(): void
    {
        // The panel shows an empty state for this, and an error would suggest the
        // report is broken rather than that there is simply nothing lost today.
        $response = $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk();

        $this->assertSame([], $response->json('carts'));
        $this->assertNull($response->json('summary.conversion_rate'));
    }

    public function test_it_pages_the_list(): void
    {
        foreach (range(1, 6) as $index) {
            $this->cart([
                'visitor_id' => str_repeat((string) $index, 32),
                'last_active_at' => now()->subDays($index + 1),
            ]);
        }

        $response = $this->actingAsAdmin()->getJson('/api/admin/carts?per_page=4')->assertOk();

        $this->assertCount(4, $response->json('carts'));
        $this->assertSame(6, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    /**
     * The abandoned filter has to survive the join it is combined with.
     *
     * The report left-joins `visits` onto the cart list, and both tables have an
     * `updated_at`, so the idle window's comparison must name the table. This is
     * asserted on the generated SQL rather than by running it because the suite
     * runs on SQLite, which resolves the ambiguity silently: the failure this
     * guards against appears only on MySQL, in production, on the one endpoint
     * whose entire job is to be looked at.
     */
    public function test_the_abandoned_filter_qualifies_the_column_it_joins(): void
    {
        $sql = Cart::query()
            ->abandoned()
            ->leftJoin('visits', 'visits.visitor_id', '=', 'carts.visitor_id')
            ->select('carts.*')
            ->toSql();

        $this->assertMatchesRegularExpression('/[`"]carts[`"]\.[`"]updated_at/', $sql);

        // Strip every qualified reference, then nothing may still mention the
        // column on its own. Quoting is accepted either way so this does not
        // depend on the connection's grammar.
        $unqualified = preg_replace('/[`"]?carts[`"]?\.[`"]?updated_at[`"]?/', '', $sql);

        $this->assertStringNotContainsString('updated_at', (string) $unqualified);
    }

    /**
     * The same for the sort key, which is also read from both tables.
     */
    public function test_the_idle_sort_orders_against_the_cart_table(): void
    {
        $sql = Cart::query()->abandoned()->recent()->toSql();

        $unqualified = preg_replace('/[`"]?carts[`"]?\.[`"]?(updated_at|id)[`"]?/', '', $sql);

        $this->assertStringNotContainsString('updated_at', (string) $unqualified);
    }

    public function test_it_works_on_mysql_where_an_unqualified_column_would_fail(): void
    {
        // Guards the point of the two tests above on the database that actually
        // rejects the ambiguity. Skipped when the suite runs on SQLite, which is
        // how it runs by default.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite does not reject ambiguous column names.');
        }

        $this->cart(['last_active_at' => now()->subDays(2)]);

        $this->actingAsAdmin()->getJson('/api/admin/carts')->assertOk();
    }
}
