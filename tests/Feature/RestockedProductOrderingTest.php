<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product that comes back to the shelf is the most recent merchandising
 * decision there is, and the shop wants it seen: first on the storefront's
 * default listing, first in the panel's products list.
 *
 * Both orders read a `restocked_at` timestamp that is written on the
 * unavailable -> available edge — the same edge the restock alert fires on,
 * so there is one definition of "came back to stock" and not two.
 */
class RestockedProductOrderingTest extends TestCase
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
            'cost_price' => 534,
            'stock' => 10,
        ], $overrides));

        $response->assertCreated();

        return Product::findOrFail($response->json('product.id'));
    }

    /**
     * The storefront's default listing, in the order the shopper reads it.
     *
     * @return array<int, int>
     */
    private function catalogueOrder(string $query = ''): array
    {
        return collect($this->getJson('/api/fr/products'.$query)
            ->assertOk()
            ->json('data'))->pluck('id')->all();
    }

    /**
     * The panel's products list, in the order the owner reads it.
     *
     * @return array<int, int>
     */
    private function adminOrder(): array
    {
        return collect($this->getJson('/api/admin/products')
            ->assertOk()
            ->json('data'))->pluck('id')->all();
    }

    public function test_returning_a_product_to_stock_records_when_it_happened(): void
    {
        $product = $this->create('Oud Royale', ['is_available' => false]);
        $this->assertNull($product->restocked_at, 'a product that never left the shelf has no restock to remember');

        $this->patchJson('/api/admin/products/'.$product->id.'/availability', ['available' => true])
            ->assertOk()
            ->assertJsonPath('product.is_available', true);

        $this->assertNotNull($product->fresh()->restocked_at);
    }

    public function test_the_flip_button_records_the_return_too(): void
    {
        $product = $this->create('Musc Blanc', ['is_available' => false]);

        // The products list's button sends no body: it flips whatever is there.
        $this->patchJson('/api/admin/products/'.$product->id.'/availability')
            ->assertOk()
            ->assertJsonPath('product.is_available', true);

        $this->assertNotNull($product->fresh()->restocked_at);
    }

    public function test_saving_the_form_to_return_a_product_records_it(): void
    {
        $product = $this->create('Ambre du soir', ['is_available' => false]);

        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Ambre du soir',
            'price' => 890,
            'cost_price' => 534,
            'stock' => 10,
            'is_available' => true,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('product.is_available', true);

        $this->assertNotNull($product->fresh()->restocked_at);
    }

    public function test_marking_a_product_out_of_stock_records_nothing(): void
    {
        $product = $this->create('Oud Royale');

        $this->patchJson('/api/admin/products/'.$product->id.'/availability', ['available' => false])
            ->assertOk()
            ->assertJsonPath('product.is_available', false);

        $this->assertNull($product->fresh()->restocked_at);
    }

    public function test_saving_an_already_available_product_records_nothing(): void
    {
        $product = $this->create('Oud Royale');

        // A price correction is not a restock: the product never left the
        // shelf, so there is no moment for the listings to sort on.
        $this->putJson('/api/admin/products/'.$product->id, [
            'name' => 'Oud Royale',
            'price' => 790,
            'cost_price' => 534,
            'stock' => 10,
            'is_available' => true,
            'is_active' => true,
        ])->assertOk();

        $this->assertNull($product->fresh()->restocked_at);
    }

    public function test_the_storefront_leads_with_the_product_just_returned_to_stock(): void
    {
        $featured = $this->create('Musc Blanc', ['is_featured' => true]);
        $returned = $this->create('Oud Royale', ['is_available' => false]);

        // Before the restock the featured pick leads, exactly as it always has.
        $this->assertSame([$featured->id, $returned->id], $this->catalogueOrder());

        $this->patchJson('/api/admin/products/'.$returned->id.'/availability', ['available' => true])
            ->assertOk();

        $this->assertSame([$returned->id, $featured->id], $this->catalogueOrder());
    }

    public function test_the_admin_products_list_leads_with_the_product_just_returned_to_stock(): void
    {
        $edited = $this->create('Musc Blanc');
        $returned = $this->create('Oud Royale', ['is_available' => false]);

        $this->patchJson('/api/admin/products/'.$returned->id.'/availability', ['available' => true])
            ->assertOk();

        // The other product is edited *after* the restock, so `updated_at`
        // alone would put it first. The restock is what leads.
        $this->putJson('/api/admin/products/'.$edited->id, [
            'name' => 'Musc Blanc',
            'price' => 790,
            'cost_price' => 534,
            'stock' => 10,
            'is_available' => true,
            'is_active' => true,
        ])->assertOk();

        $this->assertSame([$returned->id, $edited->id], $this->adminOrder());
    }

    public function test_the_newest_sort_keeps_its_own_meaning(): void
    {
        $returned = $this->create('Oud Royale');
        $newer = $this->create('Musc Blanc');

        $this->patchJson('/api/admin/products/'.$returned->id.'/availability', ['available' => true])
            ->assertOk();

        // Restock-first belongs to the default shelf. "Newest" answers a
        // different question — what arrived last — and a bottle that came
        // back is not a new arrival.
        $this->assertSame([$newer->id, $returned->id], $this->catalogueOrder('?sort=newest'));
    }
}
