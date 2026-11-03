<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every listing has to be able to show every product in it.
 *
 * Two of them could not. The search endpoint answered with a fixed handful of
 * products and no paginator at all, so a search for a common word showed its
 * first eight matches and nothing else — the rest were never on the page and
 * there was no row of page numbers to ask for them. The brand and category
 * pages did get a paginator from the API, but the storefront rendered the rows
 * and stopped: the second page was in the answer and nowhere on the screen.
 *
 * These pin the two halves separately, because they are two different bugs: the
 * API has to send the pages, and the storefront has to draw them.
 */
class ListingPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A product, with the translation row the storefront reads its name from.
     */
    private function product(string $slug, array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'price' => 890,
            'stock' => 5,
            'is_active' => true,
        ], $overrides));

        $product->translations()->create([
            'locale' => 'fr',
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'description' => 'Un parfum.',
        ]);

        return $product;
    }

    public function test_the_search_pages_through_every_match(): void
    {
        foreach (range(1, 25) as $i) {
            $this->product("oud-{$i}");
        }

        $response = $this->getJson('/api/fr/search?q=oud&per_page=12')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));

        // The page row the storefront draws its buttons from.
        $labels = array_column($response->json('meta.links'), 'label');
        $this->assertContains('2', $labels);
        $this->assertContains('3', $labels);
    }

    public function test_the_search_answers_with_more_than_the_first_eight(): void
    {
        // Twelve, which is more than the fixed handful it used to answer with.
        foreach (range(1, 12) as $i) {
            $this->product("oud-{$i}");
        }

        $response = $this->getJson('/api/fr/search?q=oud&per_page=24')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertSame(12, $response->json('meta.total'));
    }

    public function test_the_search_reaches_its_last_page(): void
    {
        foreach (range(1, 25) as $i) {
            $this->product("oud-{$i}");
        }

        $response = $this->getJson('/api/fr/search?q=oud&per_page=12&page=3')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(3, $response->json('meta.current_page'));
    }

    public function test_the_search_answers_with_the_paginator_the_storefront_needs(): void
    {
        foreach (range(1, 25) as $i) {
            $this->product("oud-{$i}");
        }

        // The whole point of the change: the search is a listing, so it carries
        // the listing's metadata rather than a bare array of rows.
        $response = $this->getJson('/api/fr/search?q=oud&per_page=12')->assertOk();

        $this->assertIsArray($response->json('meta.links'));
        $this->assertNotNull($response->json('meta.links.1.url'));
    }

    public function test_a_brand_pages_through_its_products(): void
    {
        $brand = Brand::create(['name' => 'Ibraq', 'slug' => 'ibraq', 'logo' => 'brands/ibraq.svg']);

        foreach (range(1, 15) as $i) {
            $this->product("ibraq-{$i}", ['brand_id' => $brand->id]);
        }

        $response = $this->getJson('/api/fr/brands/ibraq?per_page=12')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertSame(15, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));

        $second = $this->getJson('/api/fr/brands/ibraq?per_page=12&page=2')->assertOk();
        $this->assertCount(3, $second->json('data'));
    }

    public function test_a_category_pages_through_its_products(): void
    {
        $category = Category::create(['slug' => 'perfume']);

        foreach (range(1, 15) as $i) {
            $product = $this->product("parfum-{$i}");
            $product->categories()->attach($category->id);
        }

        $response = $this->getJson('/api/fr/categories/perfume?per_page=12')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertSame(15, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_a_listing_never_asks_for_zero_products(): void
    {
        // `paginate(0)` throws, so a bad number in a URL would be a 500.
        $this->getJson('/api/fr/products?per_page=0')->assertOk();
        $this->getJson('/api/fr/search?q=oud&per_page=0')->assertOk();
    }
}
