<?php

namespace Tests\Feature;

use App\Models\ReviewScreenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer reviews: what the storefront shows, and what the panel manages.
 *
 * The storefront's read has two properties worth pinning, because the popup
 * and the carousel both sit on it:
 *
 *  - Only active rows. A screenshot the owner took down must vanish from the
 *    shop the moment the switch flips, not at some cache's leisure.
 *  - The owner's order. The convention is the brands': edited positions lead,
 *    never-numbered rows — the column default, 0 — append in creation order,
 *    so a screenshot added after the shelf was numbered cannot steal slot one.
 *
 * The panel's side is pinned where it can go silently wrong: the image travels
 * as a *path* (the upload endpoint owns the file), the file is deleted when
 * the row is, and a swap deletes the screenshot it replaces — the media
 * library must not collect orphans behind live rows.
 */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_storefront_lists_active_reviews_in_the_owner_order(): void
    {
        $third = ReviewScreenshot::create(['image' => 'uploads/third.png', 'position' => 2]);
        $first = ReviewScreenshot::create(['image' => 'uploads/first.png', 'position' => 1]);
        $last = ReviewScreenshot::create(['image' => 'uploads/last.png']);

        $ids = array_column($this->getJson('/api/fr/reviews')->assertOk()->json('data'), 'id');

        $this->assertSame([$first->id, $third->id, $last->id], $ids);
    }

    public function test_a_review_the_owner_took_down_leaves_the_shop_but_stays_in_the_panel(): void
    {
        ReviewScreenshot::create(['image' => 'uploads/kept.png']);
        $hidden = ReviewScreenshot::create(['image' => 'uploads/hidden.png', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $shop = $this->getJson('/api/fr/reviews')->assertOk()->json('data');
        $this->assertCount(1, $shop);
        $this->assertSame(
            Storage::disk(config('chamma.disk'))->url('uploads/kept.png'),
            $shop[0]['image'],
        );

        $this->assertSame(['uploads/kept.png', 'uploads/hidden.png'], array_column(
            $this->getJson('/api/admin/reviews')->assertOk()->json('data'),
            'image_path',
        ));

        $this->assertDatabaseHas('review_screenshots', ['id' => $hidden->id, 'is_active' => false]);
    }

    public function test_the_storefront_payload_carries_the_resolved_image_url(): void
    {
        $review = ReviewScreenshot::create(['image' => 'uploads/shown.png']);

        $payload = $this->getJson('/api/fr/reviews')->assertOk()->json('data.0');

        $this->assertSame($review->id, $payload['id']);
        $this->assertSame(
            Storage::disk(config('chamma.disk'))->url('uploads/shown.png'),
            $payload['image'],
        );
    }

    public function test_an_admin_can_add_a_review(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/reviews', [
            'image' => 'uploads/new.png',
            'position' => 3,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('review.image_path', 'uploads/new.png');

        $this->assertDatabaseHas('review_screenshots', ['image' => 'uploads/new.png', 'position' => 3, 'is_active' => true]);
    }

    public function test_a_review_added_without_a_position_appends_instead_of_leading(): void
    {
        ReviewScreenshot::create(['image' => 'uploads/numbered.png', 'position' => 1]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        // The panel sends neither `position` nor `is_active` when the owner
        // left the fields alone: the column defaults must apply (append last,
        // active), and a null must never reach the columns.
        $this->postJson('/api/admin/reviews', ['image' => 'uploads/newest.png'])
            ->assertCreated()
            ->assertJsonPath('review.position', 0)
            ->assertJsonPath('review.is_active', true);

        $this->assertSame(
            [
                Storage::disk(config('chamma.disk'))->url('uploads/numbered.png'),
                Storage::disk(config('chamma.disk'))->url('uploads/newest.png'),
            ],
            array_column($this->getJson('/api/fr/reviews')->json('data'), 'image'),
        );
    }

    public function test_the_storefront_review_endpoint_refuses_an_anonymous_write(): void
    {
        $this->postJson('/api/admin/reviews', ['image' => 'uploads/x.png'])->assertUnauthorized();
        $this->assertDatabaseCount('review_screenshots', 0);
    }

    public function test_a_review_without_a_screenshot_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/reviews', ['position' => 1])->assertStatus(422);
        $this->assertDatabaseCount('review_screenshots', 0);
    }

    public function test_an_admin_can_move_a_review_and_toggle_it(): void
    {
        $review = ReviewScreenshot::create(['image' => 'uploads/moved.png']);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->putJson("/api/admin/reviews/{$review->id}", [
            'position' => 5,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('review.position', 5)->assertJsonPath('review.is_active', false);

        $this->assertDatabaseHas('review_screenshots', ['id' => $review->id, 'position' => 5, 'is_active' => false]);
    }

    public function test_swapping_a_screenshot_deletes_the_one_it_replaces(): void
    {
        Storage::fake(config('chamma.disk'));
        Storage::disk(config('chamma.disk'))->put('uploads/old.png', 'old');
        Storage::disk(config('chamma.disk'))->put('uploads/new.png', 'new');

        $review = ReviewScreenshot::create(['image' => 'uploads/old.png']);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->putJson("/api/admin/reviews/{$review->id}", ['image' => 'uploads/new.png'])->assertOk();

        $this->assertDatabaseHas('review_screenshots', ['id' => $review->id, 'image' => 'uploads/new.png']);
        Storage::disk(config('chamma.disk'))->assertMissing('uploads/old.png');
        Storage::disk(config('chamma.disk'))->assertExists('uploads/new.png');
    }

    public function test_deleting_a_review_takes_its_file_with_it(): void
    {
        Storage::fake(config('chamma.disk'));
        Storage::disk(config('chamma.disk'))->put('uploads/gone.png', 'gone');
        Storage::disk(config('chamma.disk'))->put('uploads/thumbs/gone.png', 'thumb');

        $review = ReviewScreenshot::create(['image' => 'uploads/gone.png']);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->deleteJson("/api/admin/reviews/{$review->id}")->assertOk();

        $this->assertDatabaseMissing('review_screenshots', ['id' => $review->id]);
        Storage::disk(config('chamma.disk'))->assertMissing('uploads/gone.png');
        Storage::disk(config('chamma.disk'))->assertMissing('uploads/thumbs/gone.png');
    }
}
