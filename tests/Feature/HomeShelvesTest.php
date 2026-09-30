<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The three product shelves on the home page.
 *
 * Each shows exactly four, in a carousel, so "the four most recent" and "the
 * four best selling" are literal claims the shopper can verify by counting.
 * That makes the limit and the ordering both worth asserting rather than
 * trusting, especially because a limit that silently drifts to eight is invisible
 * until someone looks at the network tab.
 *
 * These products are created through the admin API so the rows and their
 * translations are shaped exactly as production shapes them; `created_at` and
 * `sales_count` are then set directly, because neither is an admin field.
 */
class HomeShelvesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(string $name, array $overrides = []): Product
    {
        $this->admin();

        $response = $this->postJson('/api/admin/products', array_merge([
            'name' => $name,
            'price' => 890,
            'stock' => 10,
        ], $overrides));

        $response->assertCreated();

        return Product::findOrFail($response->json('product.id'));
    }

    /**
     * Stamp the two fields the admin form does not own.
     *
     * @param  array<string, mixed>  $fields
     */
    private function stamp(Product $product, array $fields): void
    {
        Product::query()->whereKey($product->id)->update($fields);
        $product->refresh();
    }

    public function test_each_shelf_shows_exactly_four(): void
    {
        // Nine products, so a limit that silently reverted to eight would be
        // caught rather than passing because the shop has fewer than eight.
        foreach (range(1, 9) as $i) {
            $this->create("Parfum {$i}", ['compare_at_price' => 1200, 'price' => 890]);
        }

        $home = $this->getJson('/api/fr/home')->assertOk();

        foreach (['best_sellers', 'new_arrivals', 'offers'] as $shelf) {
            $this->assertCount(4, $home->json($shelf), "{$shelf} should hold exactly four products");
        }
    }

    public function test_new_arrivals_are_the_four_most_recently_created(): void
    {
        $ordered = [];

        // Oldest first, each a day apart, so "most recent" is unambiguous.
        foreach (range(1, 6) as $i) {
            $product = $this->create("Parfum {$i}");
            $this->stamp($product, ['created_at' => now()->subDays(10 - $i)]);
            $ordered[] = $product;
        }

        $ids = array_column($this->getJson('/api/fr/home')->json('new_arrivals'), 'id');

        $this->assertSame(
            array_map(fn (Product $p) => $p->id, array_reverse(array_slice($ordered, -4))),
            $ids,
            'new_arrivals should be the four newest, newest first'
        );
    }

    public function test_new_arrivals_break_created_at_ties_by_id(): void
    {
        // `created_at` has one-second granularity, so a batch imported in the
        // same second ties. "The four most recent" still has to mean one specific
        // four, in one specific order, on every request.
        //
        // Honest caveat, learned by removing the explicit `id` clause and
        // watching this test stay green: SQLite answers this scan from the
        // (is_active, created_at) index backwards, and secondary indexes carry
        // the row id, so ties already come back id-descending for free. InnoDB
        // behaves the same way. The explicit clause in CatalogService is
        // therefore belt-and-braces against that coincidence, not something this
        // test can prove — SQL itself guarantees no order among equal keys. What
        // this does pin down is the contract: stable, and newest-id-first.
        $same = now()->startOfMinute();

        foreach (range(1, 6) as $i) {
            $this->stamp($this->create("Parfum {$i}"), ['created_at' => $same]);
        }

        $first = array_column($this->getJson('/api/fr/home')->json('new_arrivals'), 'id');
        $second = array_column($this->getJson('/api/fr/home')->json('new_arrivals'), 'id');

        $this->assertSame($first, $second, 'the same four, in the same order, on every request');
        $this->assertSame([6, 5, 4, 3], $first, 'the four highest ids break the tie, newest first');
    }

    public function test_best_sellers_are_the_four_with_the_highest_sales_count(): void
    {
        $sales = [1 => 3, 2 => 99, 3 => 41, 4 => 7, 5 => 60, 6 => 12];
        $byId = [];

        foreach ($sales as $i => $count) {
            $byId[$i] = $this->create("Parfum {$i}");
            $this->stamp($byId[$i], ['sales_count' => $count]);
        }

        $ids = array_column($this->getJson('/api/fr/home')->json('best_sellers'), 'id');

        // 99, 60, 41, 12 -> products 2, 5, 3, 6.
        $this->assertSame(
            [$byId[2]->id, $byId[5]->id, $byId[3]->id, $byId[6]->id],
            $ids
        );
    }

    public function test_offers_are_four_discounted_products_best_selling_first(): void
    {
        // Five products on sale, so the four-slot shelf has to choose. The two
        // that are NOT discounted outsell everything on sale, so an ordering
        // that ignored the discount filter would surface them.
        $onSaleSales = [1 => 10, 2 => 60, 3 => 30, 4 => 40, 5 => 20];
        $byId = [];

        foreach ($onSaleSales as $i => $count) {
            $byId[$i] = $this->create("Parfum {$i}", [
                'price' => 700,
                'compare_at_price' => 1000,
            ]);
            $this->stamp($byId[$i], ['sales_count' => $count]);
        }

        foreach ([6, 7] as $i) {
            $this->stamp($this->create("Parfum {$i}", ['price' => 890]), ['sales_count' => 500]);
        }

        $payload = $this->getJson('/api/fr/home')->json('offers');

        $this->assertCount(4, $payload);

        foreach ($payload as $product) {
            $this->assertGreaterThan(
                0,
                $product['discount_percent'],
                'offers must only contain products that are actually discounted'
            );
        }

        // 60, 40, 30, 20 -> products 2, 4, 3, 5. Product 1 is the fifth-best
        // sale, so it is the one that falls off the end.
        $this->assertSame(
            [$byId[2]->id, $byId[4]->id, $byId[3]->id, $byId[5]->id],
            array_column($payload, 'id')
        );
    }

    public function test_a_shelf_skips_products_that_are_not_on_the_storefront(): void
    {
        // The newest product overall is invisible, so it must not take a slot
        // that a shopper can never fill.
        $hidden = $this->create('Secret', ['is_active' => false]);
        $this->stamp($hidden, ['created_at' => now()]);

        foreach (range(1, 5) as $i) {
            $this->stamp($this->create("Parfum {$i}"), ['created_at' => now()->subDays($i)]);
        }

        $ids = array_column($this->getJson('/api/fr/home')->json('new_arrivals'), 'id');

        $this->assertNotContains($hidden->id, $ids);
        $this->assertCount(4, $ids, 'the shelf refills past the hidden product');
    }

    public function test_the_product_payload_no_longer_advertises_an_is_new_flag(): void
    {
        $this->create('Oud Royale');

        $product = $this->getJson('/api/fr/home')->json('new_arrivals.0');

        $this->assertArrayNotHasKey(
            'is_new',
            $product,
            'the flag is gone from the column; it must not survive in the payload'
        );
    }

    public function test_the_storefront_sorts_by_newest_instead_of_the_removed_flag(): void
    {
        // This is where the nav, footer and /new route now point, having been
        // repointed off ?is_new=1. Same ordering, and it has to still work.
        foreach (range(1, 3) as $i) {
            $this->stamp($this->create("Parfum {$i}"), ['created_at' => now()->subDays(5 - $i)]);
        }

        $ids = array_column(
            $this->getJson('/api/fr/products?sort=newest')->assertOk()->json('data'),
            'id'
        );

        $this->assertSame([3, 2, 1], $ids);
    }

    public function test_the_removed_flag_is_not_accepted_as_a_filter(): void
    {
        foreach (range(1, 3) as $i) {
            $this->stamp($this->create("Parfum {$i}"), ['created_at' => now()->subDays($i)]);
        }

        // Old links and any bookmarked URL must degrade to the full catalogue,
        // not to a 500 and not to a silently empty page.
        $response = $this->getJson('/api/fr/products?is_new=1')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }
}
