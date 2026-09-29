<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two flags that answer two different questions.
 *
 * `is_active` decides whether the product is on the storefront at all.
 * `is_available` decides whether it reads as buyable or is labelled out of
 * stock. These cover that they stay independent, and above all that marking a
 * product unavailable keeps it on the shelf rather than hiding it.
 */
class ProductAvailabilityTest extends TestCase
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

    /**
     * The storefront listing, as a map of product id to the row the shopper sees.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(string $query = ''): array
    {
        $rows = $this->getJson('/api/fr/products'.$query)
            ->assertOk()
            ->json('data');

        return collect($rows)->keyBy('id')->all();
    }

    public function test_a_new_product_is_published_and_available(): void
    {
        $this->admin();

        $product = $this->create();

        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_available);
        $this->assertTrue($product->in_stock);
    }

    public function test_an_unavailable_product_stays_on_the_storefront(): void
    {
        $this->admin();

        $product = $this->create(['is_available' => false]);
        $product = $product->fresh();

        $rows = $this->catalogue();

        // The whole point: available = false must not remove it from the shelf.
        $this->assertArrayHasKey($product->id, $rows);
        $this->assertFalse($rows[$product->id]['in_stock'], 'the storefront should label it out of stock');
    }

    public function test_an_inactive_product_is_hidden_even_when_it_is_available(): void
    {
        $this->admin();

        $product = $this->create(['is_active' => false, 'is_available' => true]);

        $this->assertArrayNotHasKey($product->id, $this->catalogue());
    }

    public function test_availability_is_not_derived_from_the_unit_count(): void
    {
        $this->admin();

        // Units on hand, but paused: unavailable while still holding stock.
        $paused = $this->create(['stock' => 10, 'is_available' => false])->fresh();
        $this->assertSame(10, (int) $paused->stock);
        $this->assertFalse($paused->in_stock);

        // The other way round: the flag is what the storefront reads, so an
        // available product is not turned into a sold-out one by a zero count.
        $empty = $this->create(['name' => 'Ambre du soir', 'stock' => 0, 'is_available' => true])->fresh();
        $this->assertSame(0, (int) $empty->stock);
        $this->assertTrue($empty->in_stock);
    }

    public function test_the_availability_toggle_leaves_the_publish_flag_alone(): void
    {
        $this->admin();

        $product = $this->create(['is_active' => false, 'is_available' => true]);

        $this->patchJson("/api/admin/products/{$product->id}/availability")
            ->assertOk()
            ->assertJsonPath('product.is_available', false)
            ->assertJsonPath('product.is_active', false);

        $product = $product->fresh();
        $this->assertFalse($product->is_available);
        $this->assertFalse($product->is_active, 'marking a product unavailable must not unpublish it');
    }

    public function test_the_publish_toggle_leaves_availability_alone(): void
    {
        $this->admin();

        $product = $this->create(['is_active' => true, 'is_available' => false]);

        $this->patchJson("/api/admin/products/{$product->id}/toggle")
            ->assertOk()
            ->assertJsonPath('product.is_active', false)
            ->assertJsonPath('product.is_available', false);
    }

    public function test_an_unavailable_product_can_still_be_published_and_stays_listed(): void
    {
        $this->admin();

        $product = $this->create(['is_active' => true, 'is_available' => false]);

        $rows = $this->catalogue();

        $this->assertArrayHasKey($product->id, $rows);
        $this->assertFalse($rows[$product->id]['in_stock']);
    }

    public function test_the_in_stock_filter_follows_the_availability_flag(): void
    {
        $this->admin();

        $available = $this->create(['name' => 'Musc Blanc']);
        $this->create(['name' => 'Musc Noir', 'is_available' => false]);

        $rows = $this->catalogue('?in_stock=1');

        $this->assertSame([$available->id], array_keys($rows));
    }

    public function test_availability_can_be_edited_on_the_product_form(): void
    {
        $this->admin();

        $product = $this->create(['is_available' => true, 'is_active' => true]);

        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
            'is_available' => false,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('product.is_available', false);

        $product = $product->fresh();
        $this->assertFalse($product->is_available);
        $this->assertTrue($product->is_active, 'the form must not unpublish a product it marks unavailable');
    }
}
