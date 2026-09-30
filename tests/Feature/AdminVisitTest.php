<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin side of visitor tracking.
 *
 * The panel is the only place this data is read, so these tests exist to prove
 * two things: an admin sees it, and nobody else does. The second matters more
 * — a visitor list is personal data, and it sits behind one route group.
 */
class AdminVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visit = Visit::query()->create([
            'visitor_id' => str_repeat('a', 32),
            'ip' => '203.0.113.7',
            'device' => 'mobile',
            'browser' => 'Safari',
            'os' => 'iOS',
            'path' => 'products',
            'referrer' => null,
            'locale' => 'fr',
            'visits_count' => 4,
            'first_seen' => now()->subDays(10),
            'last_seen' => now()->subHours(3),
        ]);

        $this->other = Visit::query()->create([
            'visitor_id' => str_repeat('b', 32),
            'ip' => '203.0.113.8',
            'device' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'macOS',
            'path' => 'offers',
            'referrer' => null,
            'locale' => 'en',
            'visits_count' => 1,
            'first_seen' => now()->subDay(),
            'last_seen' => now()->subHour(),
        ]);
    }

    private Visit $visit;

    private Visit $other;

    public function test_the_visitor_list_requires_authentication(): void
    {
        $this->getJson('/api/admin/visits')->assertUnauthorized();
    }

    public function test_a_signed_in_admin_sees_the_visitors(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk();

        $this->assertCount(2, $response->json('visits'));
    }

    /**
     * The identity token is an identifier, so the panel shows a fragment of it
     * rather than the whole value. It is a stable handle for a person's browsing
     * history and there is no screen an admin genuinely needs all 32 characters
     * on.
     */
    public function test_the_visitor_token_is_shortened_in_the_response(): void
    {
        $visits = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('visits');

        // Ordered by last_seen desc, so the one seen an hour ago comes first.
        $this->assertSame(str_repeat('b', 8), $visits[0]['visitor_id']);

        foreach ($visits as $visitor) {
            $this->assertSame(8, strlen($visitor['visitor_id']), 'the full token leaked');
        }
        $this->assertNotContains($this->visit->visitor_id, array_column($visits, 'visitor_id'));
    }

    public function test_the_device_filter_narrows_the_list(): void
    {
        $response = $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=mobile')
            ->assertOk();

        $this->assertCount(1, $response->json('visits'));
        $this->assertSame('mobile', $response->json('visits.0.device'));
    }

    public function test_an_unknown_device_filter_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=toaster')
            ->assertStatus(422);
    }

    /**
     * The summary describes the whole table, not the filtered page, so the
     * numbers at the top do not change as the admin pages through results.
     */
    public function test_the_summary_counts_the_whole_table_even_when_filtered(): void
    {
        $summary = $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=mobile')
            ->assertOk()
            ->json('summary');

        $this->assertSame(2, $summary['total'], 'the summary followed the filter');
        $this->assertSame(5, $summary['page_views'], 'page views must sum the running counters');
    }

    public function test_recent_visitors_are_counted_by_window(): void
    {
        $summary = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('summary');

        $this->assertSame(2, $summary['last_24h']);
        $this->assertSame(2, $summary['last_30d']);
    }

    /**
     * The paginator has to carry `links`, because the shared admin
     * <Pagination> component renders from `meta.links`. Hand-rolled
     * current_page/last_page would leave the component with nothing to draw and
     * the page would silently have no paging at all.
     */
    public function test_the_meta_carries_paginator_links(): void
    {
        $meta = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('meta');

        $this->assertArrayHasKey('links', $meta);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(2, $meta['total']);
    }

    /**
     * Read-only by design: the tracker owns these rows, and a delete button
     * next to a person's browsing history is a privacy decision, not a
     * content edit. This test pins that so nobody adds the route casually.
     */
    public function test_the_endpoint_rejects_writes(): void
    {
        $admin = $this->actingAsAdmin();

        // POST hits the index URI, which exists for GET, so Laravel answers 405
        // "method not allowed" — still a refusal, and a more accurate one than
        // 404 because the URL itself is real.
        $admin->postJson('/api/admin/visits', [])->assertStatus(405);

        // The member URI has no route at all, in any method, so these are 404.
        $admin->deleteJson("/api/admin/visits/{$this->visit->id}")->assertNotFound();
        $admin->putJson("/api/admin/visits/{$this->visit->id}", [])->assertNotFound();

        $this->assertSame(2, Visit::query()->count());
    }

    private function actingAsAdmin()
    {
        return $this->actingAs(
            User::factory()->create(['is_admin' => true])
        );
    }
}
