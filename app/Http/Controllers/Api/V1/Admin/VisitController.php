<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The visitor list for the admin panel.
 *
 * Read-only on purpose. The tracker owns these rows, and a delete endpoint on
 * them would be ambiguous — removing one visitor's data is a privacy request,
 * not a content edit — so that belongs in a deliberate maintenance action rather
 * than a button next to the table.
 */
class VisitController extends Controller
{
    /**
     * The three classes `VisitorTracker` produces, plus their empty state.
     */
    private const DEVICES = ['desktop', 'mobile', 'tablet', 'other'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device' => ['nullable', 'string', 'in:'.implode(',', self::DEVICES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $device = $validated['device'] ?? null;

        $query = Visit::query()->when($device, fn ($q) => $q->device($device))->recent();

        $page = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'visits' => collect($page->items())->map(fn (Visit $visit) => [
                'id' => $visit->id,
                // The identity cookie's value, shortened for display. It is not
                // reversible into a different visitor, but it is still an
                // identifier, so the panel shows only enough to recognise a row.
                'visitor_id' => substr($visit->visitor_id, 0, 8),
                'ip' => $visit->ip,
                'device' => $visit->device,
                'browser' => $visit->browser,
                'os' => $visit->os,
                'path' => $visit->path,
                'referrer' => $visit->referrer,
                'locale' => $visit->locale,
                'visits_count' => (int) $visit->visits_count,
                'first_seen' => $visit->first_seen?->toIso8601String(),
                'last_seen' => $visit->last_seen?->toIso8601String(),
            ])->values(),
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
     * Headline numbers for the top of the panel.
     *
     * `total` is visitors, not page views; `page_views` is the sum of their
     * running counters. The gap between the two is what tells an owner whether
     * people come back.
     */
    private function summary(): array
    {
        $since = now()->subDays(30);

        return [
            'total' => Visit::query()->count(),
            'page_views' => (int) Visit::query()->sum('visits_count'),
            'last_24h' => Visit::query()->where('last_seen', '>=', now()->subDay())->count(),
            'last_30d' => Visit::query()->where('last_seen', '>=', $since)->count(),
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
}
