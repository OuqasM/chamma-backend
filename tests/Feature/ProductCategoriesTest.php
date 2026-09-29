<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product can belong to several categories at once.
 *
 * These cover that the assignments survive a round trip, that the product is
 * listed under every category it was given rather than only one, and that
 * saving a product replaces its whole set instead of adding to it.
 */
class ProductCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function category(string $slug, int $position = 0): Category
    {
        $response = $this->postJson('/api/admin/categories', [
            'name' => $slug,
            'slug' => $slug,
            'position' => $position,
        ]);

        $response->assertCreated();

        return Category::findOrFail($response->json('category.id'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        $response = $this->postJson('/api/admin/products', array_merge([
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'stock' => 4,
        ], $overrides));

        $response->assertCreated();

        return Product::findOrFail($response->json('product.id'));
    }

    public function test_a_product_can_be_assigned_several_categories(): void
    {
        $this->admin();
        $bois = $this->category('bois', 1);
        $fleur = $this->category('fleur', 2);

        $product = $this->product(['category_ids' => [$bois->id, $fleur->id]]);

        $this->assertEqualsCanonicalizing(
            [$bois->id, $fleur->id],
            $product->categories()->pluck('categories.id')->all(),
        );
    }

    public function test_the_assigned_categories_come_back_on_the_product(): void
    {
        $this->admin();
        $this->category('bois', 1);
        $this->category('fleur', 2);

        $product = $this->product(['category_ids' => [1, 2]]);

        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'stock' => 4,
            'category_ids' => [1, 2],
        ])->assertOk()
            ->assertJsonPath('product.category_ids', fn ($ids) => count($ids) === 2)
            ->assertJsonCount(2, 'product.categories');
    }

    public function test_saving_replaces_the_whole_set_rather_than_adding_to_it(): void
    {
        $this->admin();
        $bois = $this->category('bois', 1);
        $fleur = $this->category('fleur', 2);
        $vanille = $this->category('vanille', 3);

        $product = $this->product(['category_ids' => [$bois->id, $fleur->id]]);

        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'stock' => 4,
            'category_ids' => [$vanille->id],
        ])->assertOk();

        $this->assertSame([$vanille->id], $product->fresh()->categories()->pluck('categories.id')->all());
    }

    public function test_a_product_can_be_left_in_no_category_at_all(): void
    {
        $this->admin();
        $this->category('bois', 1);

        $product = $this->product();

        $this->assertCount(0, $product->categories);
    }

    public function test_the_product_is_listed_under_every_category_it_belongs_to(): void
    {
        $this->admin();
        $bois = $this->category('bois', 1);
        $fleur = $this->category('fleur', 2);
        $vanille = $this->category('vanille', 3);

        $this->product(['category_ids' => [$bois->id, $fleur->id]]);
        $this->product(['name' => 'Musc Blanc', 'category_ids' => [$fleur->id, $vanille->id]]);
        $this->product(['name' => 'Cuir Fume', 'category_ids' => [$bois->id]]);

        // Under its own two categories...
        $this->getJson('/api/fr/categories/bois')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/fr/categories/fleur')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // ...and only the one it was given here.
        $this->getJson('/api/fr/categories/vanille')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_catalogue_filter_finds_a_product_under_any_of_its_categories(): void
    {
        $this->admin();
        $bois = $this->category('bois', 1);
        $fleur = $this->category('fleur', 2);
        $vanille = $this->category('vanille', 3);

        $this->product(['category_ids' => [$bois->id, $fleur->id]]);

        foreach (['bois', 'fleur', 'vanille'] as $slug) {
            $ids = $this->getJson('/api/fr/products?category='.$slug)
                ->assertOk()
                ->json('data.*.id');

            $expected = $slug === 'vanille' ? [] : [1];

            $this->assertSame($expected, $ids, "filtering by {$slug} returned the wrong products");
        }
    }

    public function test_the_storefront_shows_every_category_on_the_product(): void
    {
        $this->admin();
        $this->category('bois', 1);
        $this->category('fleur', 2);
        $this->category('vanille', 3);

        $this->product(['category_ids' => [1, 2]]);

        // The storefront addresses a product by slug, not id.
        $product = $this->getJson('/api/fr/products/ambre-nocturne')->assertOk()->json('product');

        $this->assertCount(2, $product['categories']);
        $this->assertEqualsCanonicalizing(
            ['bois', 'fleur'],
            array_column($product['categories'], 'slug'),
        );
    }

    public function test_a_request_without_category_ids_leaves_the_categories_alone(): void
    {
        $this->admin();
        $this->category('bois', 1);

        $product = $this->product(['category_ids' => [1]]);

        // An admin bundle from before categories became a list never sends the
        // key at all, and must not be able to wipe the assignments.
        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Ambre Nocturne',
            'price' => 800,
            'stock' => 4,
        ])->assertOk();

        $this->assertSame([1], $product->fresh()->categories()->pluck('categories.id')->all());
    }

    public function test_an_unknown_category_id_is_rejected(): void
    {
        $this->admin();
        $this->category('bois', 1);

        $this->postJson('/api/admin/products', [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'stock' => 4,
            'category_ids' => [999],
        ])->assertStatus(422)->assertJsonValidationErrors('category_ids.0');
    }

    public function test_the_same_category_cannot_be_picked_twice(): void
    {
        $this->admin();
        $this->category('bois', 1);

        $this->postJson('/api/admin/products', [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'stock' => 4,
            'category_ids' => [1, 1],
        ])->assertStatus(422)->assertJsonValidationErrors('category_ids.1');
    }
}
