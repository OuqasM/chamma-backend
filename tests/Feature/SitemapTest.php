<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['chamma.storefront_url' => 'https://chammastore.com']);
    }

    /** @test */
    public function it_serves_xml_and_names_the_storefront_not_the_api()
    {
        $product = Product::create([
            'slug' => 'oud-royale', 'sku' => 'OUD1', 'price' => 890, 'stock' => 5, 'is_active' => true,
        ]);
        $product->translations()->create([
            'locale' => 'fr', 'slug' => 'oud-royale', 'name' => 'Oud Royale', 'description' => 'Oud Royale, edition limitee.',
        ]);

        $response = $this->get('/api/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $xml = $response->getContent();

        $this->assertStringContainsString('<loc>https://chammastore.com/fr/products/oud-royale</loc>', $xml);
        // The API's own host must never appear as a page URL.
        $this->assertStringNotContainsString('<loc>http://localhost', $xml);
    }

    /** @test */
    public function it_carries_hreflang_alternates_for_every_locale()
    {
        $product = Product::create([
            'slug' => 'oud-royale', 'sku' => 'OUD1', 'price' => 890, 'stock' => 5, 'is_active' => true,
        ]);
        $product->translations()->create([
            'locale' => 'fr', 'slug' => 'oud-royale', 'name' => 'Oud Royale', 'description' => 'Oud Royale, edition limitee.',
        ]);
        $product->translations()->create([
            'locale' => 'ar', 'slug' => 'عود-رويال', 'name' => 'عود رويال', 'description' => 'عود رويال.',
        ]);

        $xml = $this->get('/api/sitemap.xml')->getContent();

        $this->assertStringContainsString('hreflang="fr" href="https://chammastore.com/fr/products/oud-royale"', $xml);
        $this->assertStringContainsString('hreflang="ar" href="https://chammastore.com/ar/products/%D8%B9%D9%88%D8%AF-%D8%B1%D9%88%D9%8A%D8%A7%D9%84"', $xml);
    }

    /** @test */
    public function it_omits_inactive_products()
    {
        Product::create([
            'slug' => 'draft', 'sku' => 'D1', 'price' => 100, 'stock' => 1, 'is_active' => false,
        ]);

        $this->assertStringNotContainsString('/fr/products/draft', $this->get('/api/sitemap.xml')->getContent());
    }

    /** @test */
    public function it_does_not_list_cart_checkout_or_search()
    {
        $xml = $this->get('/api/sitemap.xml')->getContent();

        foreach (['/fr/cart', '/fr/checkout', '/fr/search', '/fr/admin'] as $path) {
            $this->assertStringNotContainsString('<loc>https://chammastore.com'.$path, $xml);
        }
    }

    /** @test */
    public function product_api_returns_absolute_canonical_and_alternates()
    {
        $product = Product::create([
            'slug' => 'oud-royale', 'sku' => 'OUD1', 'price' => 890, 'stock' => 5, 'is_active' => true,
        ]);
        $product->translations()->create([
            'locale' => 'fr', 'slug' => 'oud-royale', 'name' => 'Oud Royale', 'description' => 'Oud Royale, edition limitee.',
        ]);

        $body = $this->getJson('/api/fr/products/oud-royale')->json('product');

        $this->assertSame('https://chammastore.com/fr/products/oud-royale', $body['canonical']);
        // `url` stays relative: it is what react-router links against.
        $this->assertSame('/fr/products/oud-royale', $body['url']);
        $this->assertSame('https://chammastore.com/fr/products/oud-royale', $body['alternates']['fr']);
    }
}
