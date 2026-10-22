<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Google Merchant Center feed: the XML that would put the catalogue on the
 * Shopping tab.
 *
 * The attributes below are the ones Google rejects an item for missing, so
 * each has a test of its own: name, description, link, image, price with its
 * currency, availability, and a barcode or an explicit "this product does not
 * carry identifiers". An attribute quietly dropped is exactly the failure that
 * costs a Shopping-tab listing and never throws anywhere a developer would
 * notice.
 */
class GoogleFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chamma.storefront_url' => 'https://chammastore.com']);
    }

    /**
     * One product with the full kit: brand, barcode, two images, a category
     * and copy in two languages. `$withImages = false` builds the catalogue
     * entry that has not had its artwork generated yet.
     */
    private function product(array $overrides = [], bool $withImages = true): Product
    {
        $brand = Brand::firstOrCreate(['slug' => 'chamal'], ['name' => 'Chamal']);
        $brand->translations()->firstOrCreate(['locale' => 'fr'], ['name' => 'Chamal']);

        $category = Category::firstOrCreate(['slug' => 'parfums']);
        $category->translations()->firstOrCreate(['locale' => 'fr'], ['name' => 'Parfums']);

        $product = Product::create(array_merge([
            'brand_id' => $brand->id,
            'slug' => 'oud-royale',
            'sku' => 'OUD-ROYALE',
            'gtin' => '3459120348956',
            'price' => 890,
            'stock' => 5,
        ], $overrides));

        $product->translations()->create(['locale' => 'fr', 'slug' => 'oud-royale', 'name' => 'Oud Royale', 'description' => 'Un parfum boisé, **intense**, des notes de oud et de rose.']);
        $product->translations()->create(['locale' => 'ar', 'slug' => 'عود-رويال', 'name' => 'عود رويال', 'description' => 'عطر خشبي نفاذ.']);

        if ($withImages) {
            ProductImage::create(['product_id' => $product->id, 'path' => 'products/oud-royale.jpg', 'is_primary' => true]);
            ProductImage::create(['product_id' => $product->id, 'path' => 'products/oud-royale-2.jpg', 'position' => 1]);
        }

        $product->categories()->attach($category);

        return $product;
    }

    public function test_it_serves_xml_at_the_locale_path(): void
    {
        $response = $this->get('/api/fr/products.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $this->assertStringContainsString(
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">',
            $response->getContent(),
        );
    }

    public function test_an_item_carries_every_field_merchant_center_requires(): void
    {
        $product = $this->product();

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringContainsString('<g:id>'.$product->id.'</g:id>', $xml);
        $this->assertStringContainsString('<g:title>Oud Royale</g:title>', $xml);
        // Markdown is reduced back to prose; the asterisks are nowhere.
        $this->assertStringContainsString('<g:description>Un parfum boisé, intense, des notes de oud et de rose.</g:description>', $xml);
        $this->assertStringContainsString('<g:link>https://chammastore.com/fr/products/oud-royale</g:link>', $xml);
        $this->assertStringContainsString('<g:price>890.00 MAD</g:price>', $xml);
        $this->assertStringContainsString('<g:availability>in stock</g:availability>', $xml);
        $this->assertStringContainsString('<g:condition>new</g:condition>', $xml);
        $this->assertStringContainsString('<g:brand>Chamal</g:brand>', $xml);
        $this->assertStringContainsString('<g:product_type>Parfums</g:product_type>', $xml);
    }

    public function test_an_image_link_names_the_host_serving_the_pictures(): void
    {
        $this->product();

        $xml = $this->get('/api/fr/products.xml')->getContent();

        // The API's APP_URL is where public/images lives, so the feed hands
        // Google a URL its fetcher can actually resolve rather than a relative
        // path it would fail on. The second image travels as an additional one.
        $this->assertMatchesRegularExpression('#<g:image_link>http://[^<]+/images/products/oud-royale\.jpg</g:image_link>#', $xml);
        $this->assertMatchesRegularExpression('#<g:additional_image_link>http://[^<]+/images/products/oud-royale-2\.jpg</g:additional_image_link>#', $xml);
    }

    public function test_a_sale_is_priced_as_the_standard_pair(): void
    {
        // The regular price is the struck-through original, and the discounted
        // amount travels as the sale price — the pair Google reads as "this
        // item is on sale from X to Y" rather than as a price change.
        $this->product([
            'price' => 690,
            'compare_at_price' => 890,
        ]);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringContainsString('<g:price>890.00 MAD</g:price>', $xml);
        $this->assertStringContainsString('<g:sale_price>690.00 MAD</g:sale_price>', $xml);
    }

    public function test_a_barcode_travels_bare_and_silences_the_identifier_flag(): void
    {
        // The one case where Google is already told everything about the item,
        // and any extra "does this have identifiers?" noise would read as if
        // the answer were no.
        $this->product();

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringContainsString('<g:gtin>3459120348956</g:gtin>', $xml);
        $this->assertStringNotContainsString('<g:identifier_exists>', $xml);
    }

    public function test_a_product_without_a_barcode_declares_so(): void
    {
        // Not left to Google's guessing: a product matched against somebody
        // else's barcode ends up listed with another shop's title and price,
        // which is worse than being absent at all.
        $this->product(['gtin' => null]);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringNotContainsString('<g:gtin>', $xml);
        $this->assertStringContainsString('<g:identifier_exists>false</g:identifier_exists>', $xml);
    }

    public function test_an_item_without_images_skips_the_image_tags(): void
    {
        // Artwork not generated yet: the feed stays valid, the item ships
        // without a picture, and Google's own error channel is where the gap
        // surfaces — not a broken XML file for every other product.
        $this->product(['gtin' => null], withImages: false);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringNotContainsString('<g:image_link>', $xml);
        $this->assertStringNotContainsString('<g:additional_image_link>', $xml);
    }

    public function test_an_unavailable_product_reads_out_of_stock(): void
    {
        // Independent of the unit count, on purpose: the admin's own switch is
        // what the storefront labels, so the feed has to say the same thing
        // about the same shelf.
        $this->product(['is_available' => false]);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringContainsString('<g:availability>out of stock</g:availability>', $xml);
    }

    public function test_inactive_products_are_left_out(): void
    {
        $this->product([
            'slug' => 'drafted',
            'sku' => 'DRAFTED',
            'is_active' => false,
        ]);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringNotContainsString('drafted', $xml);
    }

    public function test_the_feed_speaks_the_language_of_its_url(): void
    {
        // A French title in the Arabic feed would make Google flag every item
        // as "mismatched language" — each URL carries its own translation, and
        // the link follows the translated slug, escaped for XML.
        $this->product();

        $xml = $this->get('/api/ar/products.xml')->getContent();

        $this->assertStringContainsString('<g:title>عود رويال</g:title>', $xml);
        $this->assertStringContainsString(
            '<g:link>https://chammastore.com/ar/products/%D8%B9%D9%88%D8%AF-%D8%B1%D9%88%D9%8A%D8%A7%D9%84</g:link>',
            $xml,
        );
    }

    public function test_a_missing_long_description_falls_to_the_short_one(): void
    {
        // The column is NOT NULL and seeded empty, and an empty g:description
        // would reject the item at parse time; the short description is the
        // copy written first on the form, so it is the honest next source.
        $product = $this->product();
        $product->translations()->where('locale', 'fr')->first()->update([
            'description' => '',
            'short_description' => 'Un parfum boisé',
        ]);

        $xml = $this->get('/api/fr/products.xml')->getContent();

        $this->assertStringContainsString('<g:description>Un parfum boisé</g:description>', $xml);
    }

    public function test_it_is_not_counted_as_a_page_view(): void
    {
        // A feed read is a robot walking the shelf, not a shopper; counting it
        // would put a products.xml row in the visitor list for every crawl.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605'])
            ->get('/api/fr/products.xml')
            ->assertOk();

        $this->assertDatabaseCount('page_views', 0);
    }
}
