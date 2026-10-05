<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The shell bootstrap: the menu the header, drawer and footer are built from.
 *
 * It answers to three constraints at once, and each of them has a way of failing
 * quietly:
 *
 *  - **It is a menu, not an index.** It selects its columns in SQL instead of
 *    reusing `BrandResource`, so it can drop the SEO fields (`canonical`,
 *    `alternates`, `seo`, `url`) that the indexable `/brands` and `/categories`
 *    routes do need. A field reappearing here is a regression, and the payload
 *    grows by 62% for data no component reads.
 *  - **The translation fallback has to match `translated()`.** Requested locale,
 *    then the configured default, then the fallback, and an empty string counts
 *    as missing rather than as the answer. Getting the order wrong does not throw
 *    — it quietly shows Arabic shoppers the French name.
 *  - **One row per entity.** A brand translated into both the default and the
 *    fallback locale is the case that catches a `whereIn` join, which matches it
 *    twice and would print the brand twice in the menu.
 */
class NavigationPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function translate(string $table, string $foreignKey, int $id, string $locale, array $values): void
    {
        DB::table($table)->insert([$foreignKey => $id, 'locale' => $locale] + $values);
    }

    public function test_it_serves_only_the_fields_the_header_and_footer_read(): void
    {
        $brand = Brand::create(['name' => 'Rituals', 'slug' => 'rituals', 'logo' => 'brands/rituals.svg']);
        $this->translate('brand_translations', 'brand_id', $brand->id, 'fr', ['name' => 'Rituals']);

        $category = Category::create(['name' => 'Femme', 'slug' => 'femme', 'image' => 'categories/femme.jpg']);
        $this->translate('category_translations', 'category_id', $category->id, 'fr', ['name' => 'Femme']);

        // Both taxonomies are written before the single read below, and that is
        // not tidiness: the payload is cached, so a row created after the first
        // request is correctly invisible to it. A test that reads, writes and
        // reads again would be measuring the cache, not the query.
        $payload = $this->getJson('/api/fr/navigation')->assertOk()->json();

        $this->assertSame(
            ['id', 'slug', 'name', 'tagline', 'logo'],
            array_keys($payload['brands'][0]),
            'The SEO resource fields belong to /brands, not to the shell bootstrap.',
        );

        $this->assertSame(['id', 'slug', 'name', 'image'], array_keys($payload['categories'][0]));
    }

    public function test_a_brand_translated_into_every_locale_appears_once(): void
    {
        // The regression case: a `whereIn` join across the default and the
        // fallback locale matches this brand twice and prints it twice.
        $brand = Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);

        foreach (['fr', 'ar', 'en'] as $locale) {
            $this->translate('brand_translations', 'brand_id', $brand->id, $locale, ['name' => "Rituals $locale"]);
        }

        $brands = $this->getJson('/api/fr/navigation')->assertOk()->json('brands');

        $this->assertCount(1, $brands);
    }

    public function test_it_serves_the_name_in_the_requested_language(): void
    {
        $brand = Brand::create(['name' => 'Canonical', 'slug' => 'rituals']);

        foreach (['fr', 'ar', 'en'] as $locale) {
            $this->translate('brand_translations', 'brand_id', $brand->id, $locale, [
                'name' => "Rituals $locale",
                'tagline' => "tagline $locale",
            ]);
        }

        $this->assertSame('Rituals ar', $this->getJson('/api/ar/navigation')->json('brands.0.name'));
        $this->assertSame('tagline ar', $this->getJson('/api/ar/navigation')->json('brands.0.tagline'));
        $this->assertSame('Rituals en', $this->getJson('/api/en/navigation')->json('brands.0.name'));
    }

    public function test_a_language_with_no_row_falls_back_the_way_the_model_does(): void
    {
        $brand = Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);

        // Only English exists. French is the default and English the fallback, so
        // a French request has to land on the English row rather than on nothing.
        $this->translate('brand_translations', 'brand_id', $brand->id, 'en', ['name' => 'Rituals EN']);

        $this->assertSame('Rituals EN', $this->getJson('/api/fr/navigation')->json('brands.0.name'));
    }

    public function test_an_empty_translation_is_treated_as_missing(): void
    {
        // `HasTranslations::translated()` keeps walking the chain on '', so a pick
        // that stopped there would render a blank menu entry.
        $brand = Brand::create(['name' => 'Canonical', 'slug' => 'rituals']);

        $this->translate('brand_translations', 'brand_id', $brand->id, 'fr', ['name' => '']);
        $this->translate('brand_translations', 'brand_id', $brand->id, 'en', ['name' => 'Rituals EN']);

        $this->assertSame('Rituals EN', $this->getJson('/api/fr/navigation')->json('brands.0.name'));
    }

    public function test_a_brand_with_no_translations_at_all_still_gets_a_name(): void
    {
        Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);

        $this->assertSame('Rituals', $this->getJson('/api/fr/navigation')->json('brands.0.name'));
    }

    public function test_it_keeps_the_owners_order(): void
    {
        foreach ([['Zeppelin', 1], ['Mancora', 2], ['Aesop', 3]] as [$name, $position]) {
            Brand::create(['name' => $name, 'slug' => strtolower($name), 'position' => $position]);
        }

        $slugs = array_column($this->getJson('/api/fr/navigation')->json('brands'), 'slug');

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    public function test_an_inactive_brand_is_left_out(): void
    {
        Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);
        Brand::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        $slugs = array_column($this->getJson('/api/fr/navigation')->json('brands'), 'slug');

        $this->assertSame(['rituals'], $slugs);
    }

    public function test_the_category_photo_is_a_url_and_survives_a_missing_one(): void
    {
        $withPhoto = Category::create(['name' => 'Femme', 'slug' => 'femme', 'image' => 'categories/femme.jpg']);
        Category::create(['name' => 'Homme', 'slug' => 'homme', 'position' => 2]);

        $categories = $this->getJson('/api/fr/navigation')->json('categories');

        $this->assertSame(
            Storage::disk(config('chamma.disk'))->url('categories/femme.jpg'),
            $categories[0]['image']['url'],
        );
        $this->assertNull($categories[1]['image'], 'A category with no photo must not break the tile.');

        unset($withPhoto);
    }

    public function test_it_is_not_counted_as_a_page_view(): void
    {
        // The shell refetches this on every cold load and every language change,
        // so tracking it wrote a `navigation` row against every visitor on every
        // page and inflated both the visitor count and the path breakdown.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605'])
            ->getJson('/api/fr/navigation')
            ->assertOk();

        $this->assertDatabaseMissing('visits', ['path' => 'navigation']);
    }

    public function test_it_is_cached_and_the_cache_is_dropped_when_a_brand_changes(): void
    {
        $brand = Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);
        $this->translate('brand_translations', 'brand_id', $brand->id, 'fr', ['name' => 'Rituals']);

        $this->assertSame('Rituals', $this->getJson('/api/fr/navigation')->json('brands.0.name'));
        $this->assertTrue(Cache::has('navigation:fr'), 'The payload should be cached after the first read.');

        // A cache that outlives the edit is the failure mode worth naming: the
        // owner renames a brand and the menu keeps serving the old name.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->putJson('/api/admin/brands/'.$brand->id, [
            'name' => 'Rituals',
            'tagline' => 'Une nouvelle devise',
        ])->assertOk();

        $this->assertFalse(
            Cache::has('navigation:fr'),
            'Editing a brand must drop the cached menu, or the header keeps the old copy.',
        );
    }

    public function test_the_cache_is_dropped_when_a_brand_is_deleted(): void
    {
        $brand = Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);

        $this->getJson('/api/fr/navigation')->assertOk();
        $this->assertTrue(Cache::has('navigation:fr'));

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->deleteJson('/api/admin/brands/'.$brand->id)->assertOk();

        $this->assertFalse(Cache::has('navigation:fr'));
    }

    public function test_a_locale_is_only_cached_under_its_own_key(): void
    {
        // A brand renamed in French changes the Arabic menu too, so the flush is
        // not per-locale — but the cached payloads still are, or one language
        // would answer with another's copy.
        Brand::create(['name' => 'Rituals', 'slug' => 'rituals']);

        $this->getJson('/api/fr/navigation')->assertOk();

        $this->assertTrue(Cache::has('navigation:fr'));
        $this->assertFalse(Cache::has('navigation:ar'));
    }
}
