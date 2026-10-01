<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The visitor list for the admin panel, grouped by IP address.
 *
 * The `visits` table holds one row per *visitor*, keyed by the identity cookie
 * the tracker issues. Two people behind one router therefore appear as two rows
 * with the same IP, which reads like a bug on the panel even though it is the
 * tracker working correctly. The panel answers that by collapsing a shared IP
 * into a single line — one row per address, with the visit total added up and the
 * most recent browser and device shown.
 *
 * What grouping costs, and why it is kept visible:
 *
 * - A shared IP is usually several people — a home, an office, a mobile carrier.
 *   Their rows become one, so `visitors` is reported alongside the group: it is
 *   the number of distinct identity cookies behind the address, which is exactly
 *   the information the collapse would otherwise hide. Five page views from one
 *   person and one view each from five people are then still distinguishable.
 * - One person on mobile data changes IP through the day, so they appear as
 *   more than one row. That is the mirror image of the first point and cannot be
 *   fixed by grouping either way.
 *
 * The grouping is a presentation choice over data the tracker already stores
 * separately; nothing here merges or rewrites the underlying rows.
 *
 * Read-only on purpose. A delete endpoint on these rows would be ambiguous —
 * removing one visitor's data is a privacy request, not a content edit — so that
 * belongs in a deliberate maintenance action rather than a button next to the
 * table.
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
     * `ip` is nullable, and one address group can be all-null: those are visits
     * the tracker recorded without an address (some proxies hide it). PHP turns
     * a null array key into `''`, which is indistinguishable from a real key, so
     * the null group is given one of its own. A control character makes a
     * collision with a genuine address impossible.
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
            // One entry per IP address. `visitor_id` is deliberately absent: a
            // group covers several identities, so there is no single token that
            // would be the truth, and printing one member's token as if it spoke
            // for the group would be worse than printing none.
            'visits' => $groups->map(function (Visit $group) use ($latest) {
                $row = $latest->get($group->ip ?? self::NO_IP);

                return [
                    'ip' => $group->ip,
                    // From the most recent visit at this address, so the panel
                    // shows what they were last using rather than an arbitrary
                    // row's browser.
                    'device' => $row?->device,
                    'browser' => $row?->browser,
                    // How many identities share this address. See the class note:
                    // this is the number the grouping would otherwise conceal.
                    'visitors' => (int) $group->visitors,
                    'visits_count' => (int) $group->visits_count,
                    // The group's most recent visit, which is also its sort key.
                    'last_seen' => $group->last_seen?->toIso8601String(),
                ];
            })->values(),
            // `toArray()` on the paginator is what carries `links`, and the
            // shared admin <Pagination> renders from `meta.links`. Hand-rolling
            // current_page/last_page instead would leave the component with
            // nothing to draw and the page would silently have no paging.
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
            // Counts for the whole table, not the filtered page, so the summary
            // does not change as the admin pages through results.
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The grouped query the paginator reads.
     *
     * Only the group key and aggregates are selected: under MySQL's
     * `only_full_group_by` any bare column that is not grouped would be rejected,
     * and the latest browser/device are resolved separately so that the filter
     * below applies to those lookups as well.
     */
    private function groupedByIp(?string $device): Builder
    {
        return Visit::query()
            ->selectRaw('ip')
            // The running per-visitor counters are added together, so the group's
            // total is the page views from that address, not a visit count.
            ->selectRaw('sum(visits_count) as visits_count')
            // Distinct identities, not rows: two page views by one visitor are
            // one visitor.
            ->selectRaw('count(*) as visitors')
            ->selectRaw('max(last_seen) as last_seen')
            ->when($device, fn (Builder $q) => $q->device($device))
            ->groupBy('ip')
            // Ordered on the aggregate, not an alias, because the alias is only
            // portable in ORDER BY on some engines. `max(id)` breaks ties between
            // two groups seen at the same instant so paging is stable.
            ->orderByRaw('max(last_seen) desc, max(id) desc');
    }

    /**
     * The most recent row for each address on the page.
     *
     * A second query rather than a correlated subquery, so the device filter is
     * applied to the lookup the same way it is to the group. A subquery on the
     * `visits` table would ignore that filter and could report the browser of a
     * desktop visit while the panel is filtered to mobile.
     *
     * Bounded by the page, so this reads at most one row per displayed address.
     *
     * @param  Collection<int, Visit>  $groups
     * @return Collection<string, Visit>
     */
    private function latestPerGroup(Collection $groups, ?string $device): Collection
    {
        if ($groups->isEmpty()) {
            return collect();
        }

        $ips = $groups->pluck('ip')->reject(fn (?string $ip) => $ip === null)->unique()->values()->all();

        $latest = collect();

        if ($ips !== []) {
            $latest = Visit::query()
                ->when($device, fn (Builder $q) => $q->device($device))
                ->whereIn('ip', $ips)
                // `recent()` orders newest first, so the first row kept per IP is
                // the one the panel should report. `keyBy` would keep the *last*,
                // which is the opposite, hence the explicit group-and-take.
                ->recent()
                ->get()
                ->groupBy('ip')
                ->map(fn (Collection $rows) => $rows->first())
                ->mapWithKeys(fn (Visit $row, $ip) => [(string) $ip => $row]);
        }

        if ($groups->contains(fn (Visit $group) => $group->ip === null)) {
            $latest->put(self::NO_IP, Visit::query()
                ->when($device, fn (Builder $q) => $q->device($device))
                ->whereNull('ip')
                ->recent()
                ->first());
        }

        return $latest;
    }

    /**
     * Headline numbers for the top of the panel.
     *
     * `total` counts addresses, to match the rows below it; `visitors` counts
     * identities, which is what `total` used to mean and what tells an owner
     * whether an address is one person returning or several sharing a router.
     * `page_views` is the sum of the running counters.
     */
    private function summary(): array
    {
        return [
            'total' => $this->ipCount(),
            'visitors' => Visit::query()->count(),
            'page_views' => (int) Visit::query()->sum('visits_count'),
            'last_24h' => $this->ipCount(fn (Builder $q) => $q->where('last_seen', '>=', now()->subDay())),
            'last_30d' => $this->ipCount(fn (Builder $q) => $q->where('last_seen', '>=', now()->subDays(30))),
            'by_device' => Visit::query()
                ->selectRaw('device, count(*) as total')
                ->groupBy('device')
                ->pluck('total', 'device')
                ->all(),
            'top_paths' => Visit::query()
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
     * `count(distinct ip)` skips NULL, but the null group is a real row on the
     * panel, so it is added back. Without it the header would read one less than
     * the table whenever a visit had no address.
     */
    private function ipCount(?Closure $constrain = null): int
    {
        $build = fn () => Visit::query()->when($constrain !== null, fn (Builder $q) => $constrain($q));

        return $build()->distinct()->count('ip')
            + ($build()->whereNull('ip')->exists() ? 1 : 0);
    }
}
