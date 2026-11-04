<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two decisions the owner makes at the moment of the sale: a bottle that
 * has just sold out, and a size that is discontinued for good.
 *
 * Both are made while writing the order or while handing it over, so both
 * have to be one click away from there — and neither may touch the order: the
 * line keeps the name it was written with, which is what the history means.
 */
class AdminProductStockActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(array $overrides = []): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', array_merge([
            'name' => 'Oud Royale',
            'price' => 890,
            'cost_price' => 500,
            'stock' => 10,
        ], $overrides))->assertCreated();

        return Product::query()->latest('id')->firstOrFail();
    }

    /**
     * Marking a product out of stock says what it means, and says it the same
     * way twice: the panel sends the state it wants rather than flipping
     * whatever is there.
     */
    public function test_a_product_can_be_marked_out_of_stock(): void
    {
        $product = $this->product();

        $this->patchJson('/api/admin/products/'.$product->id.'/availability', ['available' => false])
            ->assertOk()
            ->assertJsonPath('product.is_available', false);

        $this->assertFalse($product->fresh()->is_available);

        // And doing it again changes nothing: the button means one thing.
        $this->patchJson('/api/admin/products/'.$product->id.'/availability', ['available' => false])
            ->assertOk()
            ->assertJsonPath('product.is_available', false);
    }

    /**
     * Without a body it still flips, which is what the products list's button
     * does.
     */
    public function test_the_availability_still_flips_without_a_body(): void
    {
        $product = $this->product();

        $this->patchJson('/api/admin/products/'.$product->id.'/availability')
            ->assertOk()
            ->assertJsonPath('product.is_available', false);

        $this->patchJson('/api/admin/products/'.$product->id.'/availability')
            ->assertOk()
            ->assertJsonPath('product.is_available', true);
    }

    /**
     * A discontinued size is taken off the list for good.
     */
    public function test_a_variant_can_be_removed(): void
    {
        $product = $this->product([
            'has_variants' => true,
            'variants' => [['name' => '50 ml'], ['name' => '100 ml']],
        ]);

        $this->assertCount(2, $product->variants);

        $this->deleteJson('/api/admin/products/'.$product->id.'/variants/'.rawurlencode('100 ml'))
            ->assertOk();

        $this->assertCount(1, $product->fresh()->variants);
        $this->assertSame('50 ml', $product->fresh()->variants->first()->name);
    }

    /**
     * A size that is not there is not a crash.
     */
    public function test_removing_a_size_that_is_not_there_says_so(): void
    {
        $product = $this->product([
            'has_variants' => true,
            'variants' => [['name' => '50 ml']],
        ]);

        $this->deleteJson('/api/admin/products/'.$product->id.'/variants/'.rawurlencode('200 ml'))
            ->assertNotFound();

        $this->assertCount(1, $product->fresh()->variants);
    }

    /**
     * Only the owner may do these.
     */
    public function test_a_guest_cannot_mark_a_product_out_of_stock(): void
    {
        $product = $this->product();

        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->patchJson('/api/admin/products/'.$product->id.'/availability', ['available' => false])
            ->assertForbidden();

        $this->deleteJson('/api/admin/products/'.$product->id.'/variants/50%20ml')->assertForbidden();
    }
}
