<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The visitor list for the admin panel, grouped by IP address.
 *
 * `page_views` is an append-only log — one row per page view, each carrying
 * the identity cookie (see `VisitorTracker`) rather than claiming the IP is
 * the person. Two people behind one router are two `visitor_id`s across rows
 * that share an `ip`, which reads like a bug on the panel even though it is
 * the tracker working correctly. The panel answers that by collapsing a
 * shared address into a single line — one row per `ip`, with the page views
 * added up, the distinct identities behind the address counted, and the most
 * recent view's browser and device shown.
 *
 * What grouping costs, and why it is kept visible:
 *
 * - A shared IP is usually several people — a home, an office, a mobile
 *   carrier. Their rows become one, so `visitors` is reported alongside the
 *   group: it is the number of distinct identities behind the address, which
 *   is exactly the information the collapse would otherwise hide. Five page
 *   views from one person and one view each from five people are then still
 *   distinguishable.
 * - One person on mobile data changes IP through the day, so they appear as
 *   more than one row. That is the mirror image of the first point and
 *   cannot be fixed by grouping either way.
 *
 * The grouping is a presentation choice over the log's rows; nothing here
 * merges or rewrites them.
 *
 * Read-only on purpose. A delete endpoint on these rows would be ambiguous —
 * removing one visitor's data is a privacy request, not a content edit — so
 * deletion belongs to the scheduled prune's retention window rather than to
 * a button next to the table.
 */
class VisitController extends Controller
{
    /**
     * The three classes `VisitorTracker` produces, plus their empty state.
     */
    private const DEVICES = ['desktop', 'mobile', 'tablet', 'other'];

    /**
     * Stands in for a null IP when using it as an array key.
     *
     * `ip` is nullable, and one address group can be all-null: those are
     * page views the tracker recorded without an address (some proxies hide
     * it). PHP turns a null array key into `''`, which is indistinguishable
     * from a real key, so the null group is given one of its own. A control
     * character makes a collision with a genuine address impossible.
     */
    private const NO_IP = "\0no-ip";

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device' => ['nullable', 'string', 'in:'.implode(',', self::DEVICES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $device = $validated['device'] ?? null;

        $page = $this->groupedByIp($device)->paginate($perPage)->withQueryString();

        $groups = collect($page->items());
        $latest = $this->latestPerGroup($groups, $device);

        return response()->json([
            // One entry per IP address. `visitor_id` is deliberately absent:
            // a group covers several identities, so there is no single token
            // that would be the truth, and printing one member's token as if
            // it spoke for the group would be worse than printing none.
            'visits' => $groups->map(function (PageView $group) use ($latest) {
                $row = $latest->get($group->ip ?? self::NO_IP);

                return [
                    'ip' => $group->ip,
                    // From the most recent page view at this address, so the
                    // panel shows what they were last using rather than an
                    // arbitrary row's browser.
                    'device' => $row?->device,
                    'browser' => $row?->browser,
                    // How many identities share this address. See the class
                    // note: this is the number the grouping would otherwise
                    // conceal.
                    'visitors' => (int) $group->visitors,
                    // The group's page views, one per row of the log.
                    'visits_count' => (int) $group->visits_count,
                    // The group's most recent page view, which is also its
                    // sort key.
                    'last_seen' => $group->created_at?->toIso8601String(),
                ];
            })->values(),
            // `toArray()` on the paginator is what carries `links`, and the
            // shared admin <Pagination> renders from `meta.links`.
            // Hand-rolling current_page/last_page instead would leave the
            // component with nothing to draw and the page would silently
            // have no paging.
            'meta' => $page->toArray() + [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'device' => $device,
                'devices' => self::DEVICES,
            ],
            // Counts for the whole log, not the filtered page, so the
            // summary does not change as the admin pages through results.
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The grouped query the paginator reads.
     *
     * Only the group key and aggregates are selected: under MySQL's
     * `only_full_group_by` any bare column that is not grouped would be
     * rejected, and the newest browser/device are resolved separately so
     * that the device filter applies to those lookups the same way it does
     * to the group.
     */
    private function groupedByIp(?string $device): Builder
    {
        return PageView::query()
            ->selectRaw('ip')
            // One row of the log is one page view, so the group's total is
            // the number of views that address was served.
            ->selectRaw('count(*) as visits_count')
            // Distinct identities, not page views: two views from one
            // visitor are one person.
            ->selectRaw('count(distinct visitor_id) as visitors')
            // The group's newest event. Aliased back onto `created_at` so
            // the model's datetime cast parses it on the way out; the panel
            // reports it as `last_seen`.
            ->selectRaw('max(created_at) as created_at')
            ->when($device, fn (Builder $q) => $q->device($device))
            ->groupBy('ip')
            // Ordered on the aggregate, not an alias, because the alias is
            // only portable in ORDER BY on some engines. `max(id)` breaks
            // ties between two groups seen at the same instant so paging is
            // stable.
            ->orderByRaw('max(created_at) desc, max(id) desc');
    }

    /**
     * The most recent page view for each address on the page.
     *
     * One query with a window function: `row_number()` numbers every
     * address's rows newest-first, so rank 1 is the row the panel should
     * read the browser and device from. The subquery form is what lets the
     * rank be filtered after the window is computed, and the device filter
     * is applied to the ranked rows so a desktop row cannot stand in for the
     * group while the panel is filtered to mobile.
     *
     * Bounded by the page, so this reads at most `per_page` addresses' worth
     * of rows rather than the whole log.
     *
     * @param  Collection<int, PageView>  $groups
     * @return Collection<string, PageView>
     */
    private function latestPerGroup(Collection $groups, ?string $device): Collection
    {
        if ($groups->isEmpty()) {
            return collect();
        }

        $ips = $groups->pluck('ip')->reject(fn (?string $ip) => $ip === null)->unique()->values()->all();
        $hasNullGroup = $groups->contains(fn (PageView $group) => $group->ip === null);

        $ranked = PageView::query()
            ->selectRaw('*, row_number() over (partition by ip order by created_at desc, id desc) as group_rank')
            ->when($device, fn (Builder $q) => $q->device($device));

        if ($ips === []) {
            // Every group on the page is addressless.
            $ranked->whereNull('ip');
        } elseif ($hasNullGroup) {
            // The addressless group is a partition of its own in the window,
            // so it is fetched alongside the real addresses and must not be
            // dropped by the page's `whereIn`.
            $ranked->where(function (Builder $q) use ($ips) {
                $q->whereIn('ip', $ips)->orWhereNull('ip');
            });
        } else {
            $ranked->whereIn('ip', $ips);
        }

        return PageView::query()
            ->fromSub($ranked, 'page_views')
            ->where('group_rank', 1)
            ->get()
            // Keyed on the same sentinel the reader looks the group up by,
            // so the addressless group resolves like any other.
            ->keyBy(fn (PageView $row) => $row->ip ?? self::NO_IP);
    }

    /**
     * Headline numbers for the top of the panel.
     *
     * `total` counts addresses, to match the rows below it; `visitors`
     * counts identities, which tells an owner whether an address is one
     * person returning or several sharing a router; `page_views` counts the
     * rows of the log itself.
     */
    private function summary(): array
    {
        return [
            'total' => $this->ipCount(),
            'visitors' => PageView::query()->distinct()->count('visitor_id'),
            'page_views' => PageView::query()->count(),
            'last_24h' => $this->ipCount(fn (Builder $q) => $q->where('created_at', '>=', now()->subDay())),
            'last_30d' => $this->ipCount(fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30))),
            'by_device' => PageView::query()
                ->selectRaw('device, count(*) as total')
                ->groupBy('device')
                ->pluck('total', 'device')
                ->all(),
            'top_paths' => PageView::query()
                ->selectRaw('path, count(*) as total')
                ->whereNotNull('path')
                ->groupBy('path')
                ->orderByDesc('total')
                ->limit(5)
                ->pluck('total', 'path')
                ->all(),
        ];
    }

    /**
     * How many address groups match, for the summary.
     *
     * `count(distinct ip)` skips NULL, but the null group is a real row on
     * the panel, so it is added back. Without it the header would read one
     * less than the table whenever a page view had no address.
     */
    private function ipCount(?Closure $constrain = null): int
    {
        $build = fn () => PageView::query()->when($constrain !== null, fn (Builder $q) => $constrain($q));

        return $build()->distinct()->count('ip')
            + ($build()->whereNull('ip')->exists() ? 1 : 0);
    }
}
