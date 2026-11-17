<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting an entity takes its pictures off the disk.
 *
 * The database cascade is the easy half; the files are the half nobody sees.
 * Every upload is written twice — `uploads/name` and the card-size
 * `uploads/thumbs/name` — so a delete that removes only the first leaves a
 * thumbnail behind that no row can reach and the panel cannot show.
 *
 * Products are soft-deleted for order history's sake, but their gallery is
 * not part of that history and is removed with the product.
 */
class MediaCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function upload(string $path): void
    {
        Storage::disk(config('chamma.disk'))->put($path, 'bytes');
        Storage::disk(config('chamma.disk'))->put('uploads/thumbs/'.basename($path), 'thumb');
    }

    private function assertGone(string $path): void
    {
        Storage::disk(config('chamma.disk'))->assertMissing($path);
        Storage::disk(config('chamma.disk'))->assertMissing('uploads/thumbs/'.basename($path));
    }

    public function test_deleting_a_category_takes_its_image_and_thumbnail(): void
    {
        Storage::fake(config('chamma.disk'));
        $this->upload('uploads/category.jpg');
        $this->admin();

        $id = $this->postJson('/api/admin/categories', [
            'name' => 'Femme',
            'slug' => 'femme',
            'image' => 'uploads/category.jpg',
        ])->assertCreated()->json('category.id');

        $this->deleteJson("/api/admin/categories/{$id}")->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $id]);
        $this->assertGone('uploads/category.jpg');
    }

    public function test_deleting_a_brand_takes_its_logo_and_thumbnail(): void
    {
        Storage::fake(config('chamma.disk'));
        $this->upload('uploads/brand.jpg');
        $this->admin();

        $id = $this->postJson('/api/admin/brands', [
            'name' => 'Rituals',
            'slug' => 'rituals',
            'logo' => 'uploads/brand.jpg',
        ])->assertCreated()->json('brand.id');

        $this->deleteJson("/api/admin/brands/{$id}")->assertOk();

        $this->assertDatabaseMissing('brands', ['id' => $id]);
        $this->assertGone('uploads/brand.jpg');
    }

    public function test_deleting_a_product_takes_its_whole_gallery(): void
    {
        Storage::fake(config('chamma.disk'));
        $this->upload('uploads/product.jpg');
        $this->upload('uploads/second.jpg');
        $this->admin();

        $id = $this->postJson('/api/admin/products', [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'cost_price' => 450,
            'stock' => 4,
            'images' => ['uploads/product.jpg', 'uploads/second.jpg'],
        ])->assertCreated()->json('product.id');

        $this->assertDatabaseCount('product_images', 2);

        $this->deleteJson("/api/admin/products/{$id}")->assertOk();

        // The product is still in the table for order history, with no gallery.
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertGone('uploads/product.jpg');
        $this->assertGone('uploads/second.jpg');
    }

    public function test_removing_a_product_image_takes_its_file_and_thumbnail(): void
    {
        Storage::fake(config('chamma.disk'));
        $this->upload('uploads/kept.jpg');
        $this->upload('uploads/removed.jpg');
        $this->admin();

        $id = $this->postJson('/api/admin/products', [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'cost_price' => 450,
            'stock' => 4,
            'images' => ['uploads/kept.jpg', 'uploads/removed.jpg'],
        ])->assertCreated()->json('product.id');

        $removed = Product::findOrFail($id)->images()->where('path', 'uploads/removed.jpg')->firstOrFail();

        $this->putJson("/api/admin/products/{$id}", [
            'name' => 'Ambre Nocturne',
            'price' => 750,
            'cost_price' => 450,
            'stock' => 4,
            'images' => ['uploads/kept.jpg'],
            'remove_images' => [$removed->id],
        ])->assertOk();

        $this->assertDatabaseMissing('product_images', ['id' => $removed->id]);
        $this->assertGone('uploads/removed.jpg');
        Storage::disk(config('chamma.disk'))->assertExists('uploads/kept.jpg');
    }
}
