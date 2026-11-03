<?php

namespace Tests\Feature;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The storefront read caches: the homepage payload, the listing bounds and the
 * catalogue listing.
 *
 * All three follow the contract `NavigationService` established: the answer is
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

    /**
     * The key the default catalogue listing is stored under.
     */
    private function listingKey(string $locale = 'fr', string $sort = 'featured', int $perPage = 24, int $page = 1): string
    {
        return "catalog:listing:{$locale}|{$sort}|{$perPage}|{$page}";
    }

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
        $this->create('Parfum 1', ['price' => 890, 'compare_at_price' => 1200]);

        $first = $this->getJson('/api/fr/home')->assertOk()->json();
        $second = $this->getJson('/api/fr/home')->assertOk()->json();

        // The cached value is the resolved resource array, serialized through
        // the cache store and back. This pins that the round trip does not
        // mangle the payload a shopper is shown on their second page view.
        $this->assertSame($first, $second);

        $this->assertArrayHasKey('hero', $second);
        $this->assertArrayHasKey('brands', $second);
        $this->assertArrayHasKey('promises', $second);
    }

    public function test_the_default_listing_is_cached(): void
    {
        $this->create('Parfum 1');

        $this->getJson('/api/fr/products?per_page=24')->assertOk();

        $this->assertTrue(
            Cache::has($this->listingKey()),
            'The catalogue listing should be cached after the first read.',
        );
    }

    public function test_a_cached_listing_is_the_same_listing(): void
    {
        $this->create('Parfum 1', ['price' => 890, 'compare_at_price' => 1200]);

        $first = $this->getJson('/api/fr/products?per_page=24')->assertOk()->json();
        $second = $this->getJson('/api/fr/products?per_page=24')->assertOk()->json();

        // The cached listing is the paginator's own items and total, rebuilt
        // into a fresh paginator. This pins that the round trip does not
        // change what the shopper is shown, and that the pagination
        // information survives it.
        $this->assertSame($first, $second);
        $this->assertSame($first['meta']['total'], $second['meta']['total']);
        $this->assertSame($first['meta']['last_page'], $second['meta']['last_page']);
    }

    public function test_a_cached_listing_pages_to_the_right_place(): void
    {
        // A paginator carries the URLs of the page it was made for. One that
        // came out of the cache with yesterday's query string would page a
        // shopper into the wrong list, so the rebuilt paginator takes its
        // path and its query string from the request being answered.
        foreach (range(1, 25) as $i) {
            $this->create("Parfum {$i}");
        }

        $this->getJson('/api/fr/products?per_page=24&sort=newest&page=2')->assertOk();

        // The same listing, asked for with an extra parameter on the query
        // string. The cache key is the listing's state and not the raw query,
        // so this is answered from the cache — and the pagination links still
        // have to carry the parameter the shopper arrived with.
        $page = $this->getJson('/api/fr/products?per_page=24&sort=newest&page=2&utm_source=newsletter')
            ->assertOk()
            ->json();

        $this->assertSame(2, $page['meta']['current_page']);
        $this->assertTrue(Cache::has($this->listingKey('fr', 'newest', 24, 2)));

        $urls = array_filter(array_column($page['meta']['links'], 'url'), 'is_string');

        $this->assertNotEmpty($urls);

        foreach ($urls as $url) {
            $this->assertStringContainsString('utm_source=newsletter', $url);
        }
    }

    public function test_a_listing_that_is_not_the_default_view_is_not_cached(): void
    {
        // A search term, a typed price bound and a slug are all open-ended:
        // caching them would mean a cache key per thing a shopper tried.
        $this->create('Parfum 1');

        $this->getJson('/api/fr/search?q=oud&per_page=24')->assertOk();
        $this->getJson('/api/fr/products?per_page=24&min_price=100')->assertOk();
        $this->getJson('/api/fr/products?per_page=24&brand=rituals')->assertOk();
        $this->getJson('/api/fr/products?per_page=13')->assertOk();

        $this->assertFalse(
            Cache::has($this->listingKey()),
            'Only the catalogue default view is cached; an open-ended listing is not.',
        );
    }

    public function test_a_cached_listing_does_not_query_the_catalogue_again(): void
    {
        // The proof that the cache is really being hit: the second read of a
        // listing costs the page view it counts and nothing else. The first
        // one pays for the products query, the count query, four eager loads
        // and the bounds.
        $this->create('Parfum 1');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/fr/products?per_page=24')->assertOk();

        $first = count(DB::getQueryLog());

        DB::flushQueryLog();

        $this->getJson('/api/fr/products?per_page=24')->assertOk();

        $second = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertGreaterThan(3, $first, 'the first read should really query the catalogue');
        $this->assertLessThanOrEqual(2, $second, 'a cached listing should not query the catalogue again');
    }

    public function test_a_cached_listing_survives_the_cache_stores_serialisation(): void
    {
        // The suite runs on the `array` cache store, which keeps what it is
        // given exactly as it is. The store the shop runs on is the database
        // one, which serialises: the models have to come back out of a
        // serialised payload as the models they went in as, eager-loaded
        // relations and all.
        $this->create('Parfum 1');

        $live = app(CatalogService::class)->paginate([], 24);

        $cached = unserialize(serialize([
            'items' => $live->items(),
            'total' => $live->total(),
        ]));

        $rebuilt = (new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            24,
            1,
            ['path' => '/'],
        ))->withQueryString();

        // Compared as the bytes that go on the wire: the round trip produces
        // the same listing, not the same objects.
        $this->assertSame(
            json_encode(ProductResource::collection($live)->resolve()),
            json_encode(ProductResource::collection($rebuilt)->resolve()),
        );
    }

    public function test_an_admin_product_write_drops_the_home_cache_and_the_bounds_and_the_listing(): void
    {
        $product = $this->create('Parfum 1');

        $this->getJson('/api/fr/home')->assertOk();
        $this->getJson('/api/fr/products?per_page=24')->assertOk();

        $this->assertTrue(Cache::has('home:fr'));
        $this->assertTrue(Cache::has('catalog:bounds'), 'The listing bounds should be cached after a listing read.');
        $this->assertTrue(Cache::has($this->listingKey()), 'The listing should be cached after a listing read.');

        // The cheapest write that legitimately changes the storefront: the
        // publish toggle, which the listing and the product count both read.
        $this->patchJson("/api/admin/products/{$product->id}/toggle")->assertOk();

        $this->assertFalse(
            Cache::has('home:fr'),
            'Hiding a product must drop the homepage cache, or the tiles keep advertising it.',
        );
        $this->assertFalse(
            Cache::has('catalog:bounds'),
            'The product count is in the bounds; the toggle must drop them.',
        );
        $this->assertFalse(
            Cache::has($this->listingKey()),
            'A hidden product must leave the cached listing, or the shop shows it until the TTL runs out.',
        );
    }

    public function test_a_placed_order_drops_the_listing_cache(): void
    {
        $product = $this->create('Parfum 1');

        $this->getJson('/api/fr/products?per_page=24')->assertOk();
        $this->assertTrue(Cache::has($this->listingKey()));

        // The checkout is the one storefront write that moves stock, and the
        // listing reads stock: a bottle that just sold out has to leave it.
        $this->postJson('/api/fr/checkout', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 rue Ibn Batouta',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertFalse(
            Cache::has($this->listingKey()),
            'An order that moved stock must drop the listing cache.',
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
