<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The storefront read caches: the homepage payload and the listing bounds.
 *
 * Both follow the contract `NavigationService` established: the answer is
 * cached, the admin writes that can change it drop it, and the TTL is the
 * backstop for a missed flush rather than the mechanism. The failure mode
 * worth naming is a cache that outlives its data — the owner raises a price
 * and the homepage keeps advertising the old one — so every test here is
 * really about the flushes.
 *
 * Reads are written before the write under test, never after, for the same
 * reason as `NavigationPayloadTest`: a row created after the first request is
 * correctly invisible to a cached payload, and a test that read-then-wrote
 * would be measuring the cache instead of the flush.
 */
class StorefrontCacheTest extends TestCase
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

    public function test_the_home_payload_is_cached_per_locale(): void
    {
        $this->create('Parfum 1');

        $this->getJson('/api/fr/home')->assertOk();

        $this->assertTrue(Cache::has('home:fr'), 'The homepage payload should be cached after the first read.');
        $this->assertFalse(Cache::has('home:ar'), 'A locale is only cached under its own key.');
    }

    public function test_a_cached_home_answer_is_the_same_answer(): void
    {
        // Discounted, so all three shelves hold the product and the round
        // trip is measured with contents in every one of them.
        $this->create('Parfum 1', ['price' => 890, 'compare_at_price' => 1200]);

        $first = $this->getJson('/api/fr/home')->assertOk()->json();
        $second = $this->getJson('/api/fr/home')->assertOk()->json();

        // The cached value is the resolved resource array, serialized through
        // the cache store and back. This pins that the round trip does not
        // mangle the payload a shopper is shown on their second page view.
        $this->assertSame($first, $second);

        foreach (['best_sellers', 'new_arrivals', 'offers'] as $shelf) {
            $this->assertArrayHasKey($shelf, $second);
            $this->assertNotEmpty($second[$shelf], "{$shelf} must survive the cache round trip with its contents.");
        }
    }

    public function test_an_admin_product_write_drops_the_home_cache_and_the_bounds(): void
    {
        $product = $this->create('Parfum 1');

        $this->getJson('/api/fr/home')->assertOk();
        $this->getJson('/api/fr/products')->assertOk();

        $this->assertTrue(Cache::has('home:fr'));
        $this->assertTrue(Cache::has('catalog:bounds'), 'The listing bounds should be cached after a listing read.');

        // The cheapest write that legitimately changes the storefront: the
        // publish toggle, which every shelf and the product count both read.
        $this->patchJson("/api/admin/products/{$product->id}/toggle")->assertOk();

        $this->assertFalse(
            Cache::has('home:fr'),
            'Hiding a product must drop the homepage cache, or the shelves keep advertising it.',
        );
        $this->assertFalse(
            Cache::has('catalog:bounds'),
            'The product count is in the bounds; the toggle must drop them.',
        );
    }

    public function test_a_placed_order_drops_the_home_cache(): void
    {
        $product = $this->create('Parfum 1');

        $this->getJson('/api/fr/home')->assertOk();
        $this->assertTrue(Cache::has('home:fr'));

        // The checkout is the one storefront write that moves stock, and the
        // shelves read stock: a bottle that just sold out must leave them.
        $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertFalse(
            Cache::has('home:fr'),
            'An order that moved stock must drop the homepage cache.',
        );
    }

    public function test_a_brand_write_drops_the_home_cache_and_the_bounds(): void
    {
        $this->create('Parfum 1');

        $this->getJson('/api/fr/home')->assertOk();
        $this->getJson('/api/fr/products')->assertOk();

        $this->assertTrue(Cache::has('home:fr'));
        $this->assertTrue(Cache::has('catalog:bounds'));

        $this->admin();
        $brandId = $this->postJson('/api/admin/brands', ['name' => 'Rituals'])->assertCreated()->json('brand.id');

        // The caches above were read before this row existed, so they have to
        // be re-read before the rename can prove anything about the flush.
        $this->getJson('/api/fr/home')->assertOk();
        $this->getJson('/api/fr/products')->assertOk();
        $this->assertTrue(Cache::has('home:fr'));
        $this->assertTrue(Cache::has('catalog:bounds'));

        $this->putJson("/api/admin/brands/{$brandId}", ['name' => 'Renamed House'])->assertOk();

        $this->assertFalse(Cache::has('home:fr'), 'A brand rename changes the home tiles.');
        $this->assertFalse(Cache::has('catalog:bounds'), 'Brand writes flush the bounds uniformly, whatever they changed.');
    }
}
