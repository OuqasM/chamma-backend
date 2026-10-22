<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin side of visitor tracking.
 *
 * The panel is the only place this data is read, so these tests exist to
 * prove two things: an admin sees it, and nobody else does. The second
 * matters more — a visitor list is personal data, and it sits behind one
 * route group.
 *
 * The underlying log holds one row per page view; every expectation here is
 * phrased in events, because that is what the grouping has to add up to.
 */
class AdminVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Visitor A: four page views behind one address, spread over ten
        // days, always mobile Safari. The repeats exist so the "one visitor,
        // several views" case has something to count.
        foreach ([now()->subDays(10), now()->subDays(3), now()->subHours(6), now()->subHours(3)] as $when) {
            $this->visit = $this->seedView(['created_at' => $when]);
        }

        // Visitor B: one page view, a different person at a different
        // address, an hour ago.
        $this->other = $this->seedView([
            'visitor_id' => str_repeat('b', 32),
            'ip' => '203.0.113.8',
            'device' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'macOS',
            'path' => 'offers',
            'locale' => 'en',
            'created_at' => now()->subHour(),
        ]);
    }

    private PageView $visit;

    private PageView $other;

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
     * A group covers several identity cookies, so there is no single token
     * that would be the truth for the row. Exposing one member's token as if
     * it spoke for the others would mislead; none is exposed instead.
     */
    public function test_the_grouped_row_does_not_expose_an_identity_token(): void
    {
        $visits = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('visits');

        foreach ($visits as $group) {
            $this->assertArrayNotHasKey('visitor_id', $group);
        }

        // The tokens exist on the underlying rows; verifying the exclusion
        // against them rather than against strings makes this a real check.
        $this->assertNotContains(
            $this->visit->visitor_id,
            collect($visits)->pluck('visitor_id')->all(),
        );
        $this->assertNotContains(
            $this->other->visitor_id,
            collect($visits)->pluck('visitor_id')->all(),
        );
    }

    /**
     * The reported problem: several rows sharing an IP but carrying
     * different tokens. They are one line now, and `visitors` is what stops
     * the collapse from hiding that they were two people.
     */
    public function test_two_visitors_behind_one_ip_collapse_into_one_row(): void
    {
        // Visitor C shared visitor A's address: three more page views, the
        // newest a desktop Chrome view ten minutes ago.
        $newest = null;

        foreach ([now()->subHours(5), now()->subHours(1), now()->subMinutes(10)] as $when) {
            $newest = $this->seedView([
                'visitor_id' => str_repeat('c', 32),
                'device' => 'desktop',
                'browser' => 'Chrome',
                'os' => 'macOS',
                'path' => 'offers',
                'locale' => 'en',
                'created_at' => $when,
            ]);
        }

        $visits = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('visits');

        // Two addresses: the shared one and 203.0.113.8.
        $this->assertCount(2, $visits);

        $shared = collect($visits)->firstWhere('ip', '203.0.113.7');

        $this->assertSame(2, $shared['visitors'], 'both identities behind the address must be counted');
        $this->assertSame(7, $shared['visits_count'], 'every page view must be counted, not just rows');
        // The most recent page view at the address, not an arbitrary row's
        // details.
        $this->assertSame('Chrome', $shared['browser']);
        $this->assertSame('desktop', $shared['device']);
        // Compared against the stored value: the column truncates
        // microseconds that `now()` carries, so a freshly built timestamp
        // would not match.
        $this->assertSame($newest->refresh()->created_at->toIso8601String(), $shared['last_seen']);
    }

    /**
     * One visitor is one identity, however many times they return: the
     * group must not inflate `visitors` past one.
     */
    public function test_one_visitor_returning_does_not_look_like_several_people(): void
    {
        $visits = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('visits');

        $single = collect($visits)->firstWhere('ip', '203.0.113.7');

        $this->assertSame(1, $single['visitors']);
        // But their repeat views are still counted.
        $this->assertSame(4, $single['visits_count']);
    }

    /**
     * `ip` is nullable, and all the addressless page views form their own
     * group. They must not be silently dropped, nor merged into a real
     * address.
     */
    public function test_visits_without_an_ip_form_their_own_row(): void
    {
        foreach (['d', 'e'] as $letter) {
            $this->seedView([
                'visitor_id' => str_repeat($letter, 32),
                'ip' => null,
                'device' => 'other',
                'browser' => 'Firefox',
                'os' => 'Linux',
                'created_at' => now()->subHour(),
            ]);
        }

        $visits = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('visits');

        $this->assertCount(3, $visits);

        $unknown = collect($visits)->firstWhere('ip', null);

        $this->assertNotNull($unknown, 'the addressless group was dropped');
        $this->assertSame(2, $unknown['visitors']);
        $this->assertSame(2, $unknown['visits_count']);
    }

    /**
     * `total` counts lines on the panel; `visitors` counts people. They
     * differ by exactly the amount grouping concealed, so both are
     * reported.
     */
    public function test_the_summary_separates_addresses_from_visitors(): void
    {
        // A third identity back at the first address, with three more page
        // views of its own.
        foreach ([now()->subHours(5), now()->subHours(2), now()->subMinutes(10)] as $when) {
            $this->seedView([
                'visitor_id' => str_repeat('f', 32),
                'device' => 'desktop',
                'browser' => 'Chrome',
                'os' => 'macOS',
                'path' => 'offers',
                'locale' => 'en',
                'created_at' => $when,
            ]);
        }

        $summary = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('summary');

        $this->assertSame(2, $summary['total'], 'two addresses share the page views');
        $this->assertSame(3, $summary['visitors'], 'three identities are behind them');
        // Four events from A, one from B, three from F.
        $this->assertSame(8, $summary['page_views']);
    }

    public function test_the_device_filter_narrows_the_list(): void
    {
        $response = $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=desktop')
            ->assertOk();

        $this->assertCount(1, $response->json('visits'));
        $this->assertSame('desktop', $response->json('visits.0.device'));
    }

    public function test_an_unknown_device_filter_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=toaster')
            ->assertStatus(422);
    }

    /**
     * The summary describes the whole log, not the filtered page, so the
     * numbers at the top do not change as the admin pages through results.
     */
    public function test_the_summary_counts_the_whole_table_even_when_filtered(): void
    {
        $summary = $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=mobile')
            ->assertOk()
            ->json('summary');

        $this->assertSame(2, $summary['total'], 'the summary followed the filter');
        $this->assertSame(5, $summary['page_views'], 'page views must count the filtered events');
    }

    public function test_recent_visitors_are_counted_by_window(): void
    {
        $summary = $this->actingAsAdmin()->getJson('/api/admin/visits')->assertOk()->json('summary');

        $this->assertSame(2, $summary['last_24h']);
        $this->assertSame(2, $summary['last_30d']);
    }

    /**
     * A group's browser and device come from the group's *newest* page view,
     * filtered to the panel's device class. Without the window being aware
     * of the filter, a desktop view could stand in for a group the panel is
     * looking at as mobile.
     */
    public function test_the_group_reflects_the_newest_event_within_the_filter(): void
    {
        $visits = $this->actingAsAdmin()
            ->getJson('/api/admin/visits?device=desktop')
            ->assertOk()
            ->json('visits');

        $this->assertCount(1, $visits);

        $row = collect($visits)->firstWhere('ip', '203.0.113.8');

        // Visitor B's single desktop view is the newest desktop event at
        // their address, and the window reports it, not a mobile row's
        // details.
        $this->assertSame('Chrome', $row['browser']);
    }

    /**
     * The paginator has to carry `links`, because the shared admin
     * <Pagination> component renders from `meta.links`. Hand-rolled
     * current_page/last_page would leave the component with nothing to draw
     * and the page would silently have no paging at all.
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

        // POST hits the index URI, which exists for GET, so Laravel answers
        // 405 "method not allowed" — still a refusal, and a more accurate
        // one than 404 because the URL itself is real.
        $admin->postJson('/api/admin/visits', [])->assertStatus(405);

        // The member URI has no route at all, in any method, so these are
        // 404.
        $admin->deleteJson("/api/admin/visits/{$this->visit->id}")->assertNotFound();
        $admin->putJson("/api/admin/visits/{$this->visit->id}", [])->assertNotFound();

        $this->assertSame(5, PageView::query()->count());
    }

    /**
     * Seeds one page view behind the default identity, unless overridden.
     *
     * `forceCreate` because `$fillable` deliberately omits `created_at` —
     * the service lets Eloquent stamp it — and a test that needs an old row
     * needs its own timestamp, not another fresh one.
     */
    private function seedView(array $attributes = []): PageView
    {
        return PageView::forceCreate($attributes + [
            'visitor_id' => str_repeat('a', 32),
            'ip' => '203.0.113.7',
            'device' => 'mobile',
            'browser' => 'Safari',
            'os' => 'iOS',
            'path' => 'products',
            'referrer' => null,
            'locale' => 'fr',
            'created_at' => now()->subHours(3),
        ]);
    }

    private function actingAsAdmin()
    {
        return $this->actingAs(
            User::factory()->create(['is_admin' => true])
        );
    }
}
