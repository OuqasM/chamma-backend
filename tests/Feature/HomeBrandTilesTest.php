<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The homepage's brand carousel.
 *
 * The tiles are the brand logo with the name on it, so `BrandCard` reads four
 * fields. This list used to go out through `BrandResource` — description,
 * origin, canonical URL and three hreflang alternates per brand, 7.9 KB of a
 * 15.3 KB payload on a twelve-brand shop, for a row of pictures.
 *
 * Three things here can go wrong without throwing:
 *
 *  - **A brand with several products appearing several times.** The "only
 *    brands with something to sell" rule is a join rather than a subquery, and a
 *    join over `products` matches once per product. Four products on one brand
 *    means four tiles of it in the carousel. The uniqueness is a property of the
 *    row set, not of any single row, so it has to be pinned explicitly.
 *  - **The SEO fields creeping back.** The homepage is not an index; nothing on
 *    it links to `/brands/{slug}`, so `canonical` and `alternates` have nowhere
 *    to go.
 *  - **A missing translation.** The same locale → default → fallback chain as
 *    `translated()`, including that an empty string falls through rather than
 *    winning. A brand with no Arabic row must not render a blank tile.
 */
class HomeBrandTilesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `logo` takes a sentinel rather than a nullable default, so the test that
     * covers a brand with no logo can actually ask for one. With `?? 'default'`
     * it could not: a caller passing `''` got the default path back and the
     * brand rendered fine, so the test would have been checking nothing.
     */
    private const NO_LOGO = '<no-logo>';

    private function makeBrand(string $name, int $position = 1, string $logo = self::NO_LOGO): Brand
    {
        if ($logo === self::NO_LOGO) {
            $logo = 'brands/'.Str::slug($name).'.svg';
        }

        return Brand::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'position' => $position,
            'logo' => $logo === '' ? null : $logo,
        ]);
    }

    private function attachProduct(Brand $brand, string $suffix = 'a'): void
    {
        Product::create([
            'brand_id' => $brand->id,
            'category_id' => Category::create(['slug' => 'cat-'.$suffix])->id,
            'slug' => 'p-'.$suffix,
            'sku' => 'SKU-'.$suffix,
            'price' => 10,
            'stock' => 5,
        ]);
    }

    private function translate(Brand $brand, string $locale, ?string $name, ?string $tagline = null): void
    {
        DB::table('brand_translations')->insert(array_filter([
            'brand_id' => $brand->id,
            'locale' => $locale,
            'name' => $name,
            'tagline' => $tagline,
        ], fn ($value) => $value !== null));
    }

    public function test_it_serves_only_what_a_tile_reads(): void
    {
        Storage::fake('public');

        $brand = $this->makeBrand('Rituals');
        $this->attachProduct($brand);
        $this->translate($brand, 'fr', 'Rituals');

        $tile = $this->getJson('/api/fr/home')->assertOk()->json('brands.0');

        $this->assertSame(['id', 'slug', 'name', 'logo'], array_keys($tile));

        // None of these are on a tile, and each of them is what made the payload
        // heavy. `canonical` and `alternates` in particular belong to `/brands`,
        // which is the indexable route for this content.
        $this->assertArrayNotHasKey('canonical', $tile);
        $this->assertArrayNotHasKey('alternates', $tile);
        $this->assertArrayNotHasKey('description', $tile);
        $this->assertArrayNotHasKey('origin', $tile);
    }

    public function test_a_brand_with_several_products_gets_one_tile(): void
    {
        Storage::fake('public');

        $brand = $this->makeBrand('Rituals');
        $this->translate($brand, 'fr', 'Rituals');

        foreach (['a', 'b', 'c', 'd'] as $index => $suffix) {
            Product::create([
                'brand_id' => $brand->id,
                'category_id' => Category::create(['slug' => 'cat-'.$suffix])->id,
                'slug' => 'p-'.$suffix,
                'sku' => 'SKU-'.$suffix,
                'price' => 10 + $index,
                'stock' => 5,
            ]);
        }

        $brands = $this->getJson('/api/fr/home')->assertOk()->json('brands');

        $this->assertCount(1, $brands, 'A brand with four products must not become four tiles.');
    }

    public function test_a_brand_with_nothing_published_is_not_a_tile(): void
    {
        Storage::fake('public');

        $empty = $this->makeBrand('Never Shipped');
        $this->translate($empty, 'fr', 'Never Shipped');

        $soldOut = $this->makeBrand('Unpublished Product', 2);
        $this->translate($soldOut, 'fr', 'Unpublished Product');
        $product = new Product([
            'brand_id' => $soldOut->id,
            'slug' => 'hidden',
            'sku' => 'SKU-HIDDEN',
            'price' => 10,
            'stock' => 5,
        ]);
        $product->is_active = false;
        $product->save();

        $this->assertSame([], $this->getJson('/api/fr/home')->assertOk()->json('brands'));
    }

    public function test_a_brand_without_a_logo_is_left_out(): void
    {
        Storage::fake('public');

        $withLogo = $this->makeBrand('Rituals');
        $this->translate($withLogo, 'fr', 'Rituals');
        $this->attachProduct($withLogo, 'a');

        $noLogo = $this->makeBrand('Placeholder', 2, logo: '');
        $this->translate($noLogo, 'fr', 'Placeholder');
        $this->attachProduct($noLogo, 'b');

        $slugs = array_column($this->getJson('/api/fr/home')->assertOk()->json('brands'), 'slug');

        // `''` as well as `null`. A column check for `NOT NULL` would let the
        // empty string through to `url('')` and render a broken tile, which is
        // exactly what the storefront's own truthiness test used to prevent.
        $this->assertSame(['rituals'], $slugs);
    }

    public function test_a_tile_falls_back_to_the_default_then_the_fallback_locale(): void
    {
        Storage::fake('public');

        $missing = $this->makeBrand('Missing Arabic', 1);
        $this->translate($missing, 'fr', 'Nom Français');
        $this->attachProduct($missing, 'a');

        $empty = $this->makeBrand('Empty Arabic', 2);
        $this->translate($empty, 'ar', '');
        $this->translate($empty, 'fr', 'Nom De Secours');
        $this->attachProduct($empty, 'b');

        $slugs = $this->getJson('/api/ar/home')->assertOk()->json('brands');

        // The empty Arabic row must fall through to French rather than winning
        // with a blank tile — the case that shows up as a nameless card and no
        // error anywhere.
        $this->assertSame(
            ['missing-arabic' => 'Nom Français', 'empty-arabic' => 'Nom De Secours'],
            array_column($slugs, 'name', 'slug'),
        );
    }

    public function test_the_homepage_is_still_tracked(): void
    {
        Storage::fake('public');

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605'])
            ->getJson('/api/fr/home')
            ->assertOk();

        // The homepage is a page: a visitor lands on it and the analytics needs
        // to know. This is the opposite of `/navigation`, which the shell
        // refetches on every page and which must stay out of the visit counts.
        // The recorded path is `home`, not `/fr/home`: the tracker maps an API
        // request back onto the storefront path behind it.
        $this->assertDatabaseHas('visits', ['path' => 'home', 'locale' => 'fr']);
    }
}
