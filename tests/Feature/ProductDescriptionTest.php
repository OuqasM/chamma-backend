<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product is written once, in one language, but read in three.
 *
 * These cover the seam that makes that work: copy submitted per language has to
 * land on the row that serves that language, a language left blank has to fall
 * through to one that is filled, and a language the shop does not sell in must
 * not be creatable through the payload.
 */
class ProductDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): Product
    {
        $response = $this->postJson('/api/admin/products', array_merge([
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
        ], $overrides));

        $response->assertCreated();

        return Product::findOrFail($response->json('product.id'));
    }

    public function test_it_stores_copy_for_each_language_that_was_filled_in(): void
    {
        $this->admin();

        $product = $this->create([
            'descriptions' => [
                'fr' => 'Un oud intense.',
                'ar' => 'عود ملكي.',
                'en' => 'A deep oud.',
            ],
            'short_descriptions' => [
                'fr' => 'Oud intense',
                'en' => 'Deep oud',
            ],
        ]);

        $rows = $product->translations()->pluck('description', 'locale');

        $this->assertSame('Un oud intense.', $rows['fr']);
        $this->assertSame('عود ملكي.', $rows['ar']);
        $this->assertSame('A deep oud.', $rows['en']);

        $shorts = $product->translations()->pluck('short_description', 'locale');

        $this->assertSame('Oud intense', $shorts['fr']);
        $this->assertSame('Deep oud', $shorts['en']);
        // Arabic was left blank, so it holds nothing rather than French text.
        $this->assertNotSame('Oud intense', $shorts['ar']);
    }

    public function test_a_language_left_blank_falls_through_to_one_that_is_filled(): void
    {
        $this->admin();

        $product = $this->create([
            'descriptions' => [
                'fr' => '',
                'en' => 'A deep oud.',
            ],
        ]);

        $this->assertSame('', $product->translations()->where('locale', 'fr')->value('description'));

        // French is the default locale, so it must show English rather than blank.
        $this->assertSame('A deep oud.', $product->description('fr'));
        $this->assertSame('A deep oud.', $product->description('ar'));
    }

    public function test_clearing_one_language_leaves_the_others_alone(): void
    {
        $this->admin();

        $product = $this->create([
            'descriptions' => ['fr' => 'Un oud intense.', 'en' => 'A deep oud.'],
        ]);

        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
            'descriptions' => ['fr' => 'Version corrigée.'],
        ])->assertOk();

        $rows = $product->fresh()->translations()->pluck('description', 'locale');

        $this->assertSame('Version corrigée.', $rows['fr']);
        // Untouched keys must not blank a language on save.
        $this->assertSame('A deep oud.', $rows['en']);
    }

    public function test_a_language_the_shop_does_not_sell_in_cannot_be_written(): void
    {
        $this->admin();

        config(['chamma.locales' => ['fr' => config('chamma.locales.fr'), 'en' => config('chamma.locales.en')]]);

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
            'descriptions' => ['fr' => 'Un oud.', 'de' => 'Ein Oud.'],
        ])->assertCreated();

        $product = Product::where('slug', 'oud-royale')->firstOrFail();

        $this->assertNull($product->translations()->where('locale', 'de')->first());
        $this->assertSame('Un oud.', $product->translations()->where('locale', 'fr')->value('description'));
    }

    public function test_the_single_language_fields_still_write_the_fallback_row(): void
    {
        $this->admin();

        $product = $this->create([
            'description' => 'Legacy single-language copy.',
            'short_description' => 'Legacy short',
        ]);

        $english = $product->translations()->where('locale', config('chamma.fallback_locale'))->first();

        $this->assertSame('Legacy single-language copy.', $english->description);
        $this->assertSame('Legacy short', $english->short_description);
        // Every other language reads it through the fallback chain.
        $this->assertSame('Legacy single-language copy.', $product->description('fr'));
    }

    public function test_a_cleared_single_language_description_stores_an_empty_string(): void
    {
        $this->admin();

        $product = $this->create(['description' => 'Copy.']);

        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
            'description' => '',
        ])->assertOk();

        $english = $product->fresh()->translations()->where('locale', config('chamma.fallback_locale'))->first();

        // The column is NOT NULL, so this must not have become null.
        $this->assertSame('', $english->description);
    }

    public function test_the_storefront_returns_rendered_html_and_keeps_the_source(): void
    {
        $this->admin();

        $this->create([
            'descriptions' => [
                'en' => "## Notes\n**Bergamot** then rose.\n\nSee [the house](https://chama.ma).",
            ],
        ]);

        $response = $this->getJson('/api/en/products/oud-royale')->assertOk();

        $this->assertStringContainsString('<h2>Notes</h2>', $response->json('product.description_html'));
        $this->assertStringContainsString('<strong>Bergamot</strong>', $response->json('product.description_html'));
        $this->assertStringContainsString('href="https://chama.ma"', $response->json('product.description_html'));

        // The raw markdown is still there for the admin form to re-edit.
        $this->assertStringContainsString('## Notes', $response->json('product.description'));
    }

    public function test_author_html_is_escaped_on_the_way_out(): void
    {
        $this->admin();

        $this->create(['descriptions' => ['en' => '<script>alert(1)</script>']]);

        $html = $this->getJson('/api/en/products/oud-royale')->assertOk()->json('product.description_html');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_meta_description_is_plain_text(): void
    {
        $this->admin();

        $this->create([
            'descriptions' => ['en' => '**Bold** text with a [link](https://chama.ma).'],
        ]);

        $seo = $this->getJson('/api/en/products/oud-royale')->assertOk()->json('product.seo.description');

        $this->assertStringNotContainsString('<', $seo);
        $this->assertStringNotContainsString('**', $seo);
        $this->assertStringNotContainsString('https', $seo);
        $this->assertStringContainsString('Bold text', $seo);
    }
}
