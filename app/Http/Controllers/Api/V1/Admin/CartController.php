<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Carts that were filled and never checked out.
 *
 * The storefront cart lives in `localStorage` until it is submitted, so before
 * this table existed a cart that was filled and abandoned left no trace at all:
 * `orders` only ever received the ones that converted, and `visits` only knew
 * someone had been on a product page. There was no way to tell a visitor who
 * walked away from one who walked past, which is the single most useful thing to
 * know about a basket.
 *
 * Read-only, for the same reason the visitor list is. A cart row is a record of
 * what an unidentified person was about to buy, so erasing one is a privacy
 * action rather than a content edit, and it belongs in a deliberate maintenance
 * command (`carts:purge`) rather than a delete button beside the table.
 */
class CartController extends Controller
{
    /**
     * The three states the filter offers.
     *
     * `abandoned` and `open` are both "did not convert" and differ only by how
     * long ago the cart was last touched. They are separate here because a
     * shopper who is filling a cart right now is not a lost sale, and counting
     * them together is what makes abandonment numbers useless.
     */
    private const STATUSES = ['abandoned', 'open', 'converted'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $status = $validated['status'] ?? 'abandoned';

        $page = $this->query($status)
            // The items for the carts on this page, and the orders they became.
            // Both eager loaded so the 50 rows below cost three queries in total
            // rather than 50: a per-row `order_id` lookup here is the classic N+1,
            // and on the abandoned filter — the panel's default — every row
            // would have run one and come back empty.
            ->with(['items', 'order'])
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'carts' => collect($page->items())->map(fn (Cart $cart) => $this->row($cart))->values(),
            // `toArray()` is what carries `links`; the shared admin <Pagination>
            // renders from `meta.links`, and hand-rolling page counters instead
            // would leave it nothing to draw.
            'meta' => $page->toArray() + [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'status' => $status,
                'statuses' => self::STATUSES,
            ],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The filtered list, with the visitor's network details joined on.
     *
     * Defaults to `abandoned` rather than to everything, because the panel is
     * opened to answer one question — what is being lost — and a default of "all
     * carts" would open on converted orders, which is the answer the shop already
     * has.
     *
     * The `visits` join is what keeps the panel to a single query for the page.
     * The address is read across rather than stored on the cart, so a cart row
     * holds no network data of its own, and a visitor with several carts
     * contributes one visit row that all of them read. A cart whose sync was the
     * browser's first request has no visit row at all; the join is a left one,
     * so that cart is reported with a null address instead of being dropped.
     */
    private function query(string $status): Builder
    {
        $query = match ($status) {
            'converted' => Cart::query()->converted(),
            'open' => Cart::query()->unconverted()->where(
                'carts.updated_at',
                '>',
                now()->subHours(Cart::IDLE_HOURS)
            ),
            // `abandoned` and anything else that reaches here: unconverted and idle.
            // The validation above rejects an unknown status with a 422 before
            // this runs, so the `default` arm is a second line of defence rather
            // than the behaviour: it still means a value added here later cannot
            // accidentally become "return everything".
            default => Cart::query()->abandoned(),
        };

        return $query
            ->leftJoin('visits', 'visits.visitor_id', '=', 'carts.visitor_id')
            // `carts.*` is explicit because the join adds columns that would
            // otherwise overwrite same-named ones on the model.
            ->select('carts.*')
            // Aliased, not taken as `ip`/`device`, so an address from a visit row
            // can never be mistaken for a column of `carts`. The alias has to go
            // through `raw`: `addSelect(['visitor_ip' => 'visits.ip'])` keeps the
            // key only as an array index and selects the bare column, which would
            // leave `$cart->visitor_ip` null on every row and quietly drop the
            // column from the report.
            ->addSelect([
                'visitor_ip' => DB::raw('visits.ip as visitor_ip'),
                'visitor_device' => DB::raw('visits.device as visitor_device'),
            ])
            // Qualified: after the join, `updated_at` exists on both tables and an
            // unqualified reference is ambiguous SQL on MySQL. `id` is the
            // tie-break so two carts last touched in the same second page
            // consistently.
            ->orderByDesc('carts.updated_at')
            ->orderByDesc('carts.id');
    }

    /**
     * One cart as the panel draws it.
     *
     * `visitor_id` is included. It is a random first-party token and it is the
     * honest identity of the cart — the same token the visitor list shows, so an
     * owner reading "someone left a 1,200 DH basket" can match it against a
     * visit row. It is not personal data on its own, and hiding it would leave
     * the panel unable to answer the question it exists for.
     */
    private function row(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'visitor_id' => $cart->visitor_id,
            'locale' => $cart->locale,
            'subtotal' => (float) $cart->subtotal,
            'items_count' => (int) $cart->items_count,
            'updates_count' => (int) $cart->updates_count,
            'created_at' => $cart->created_at?->toIso8601String(),
            // Also the sort key, and the age the "abandoned" filter keys on.
            'last_active_at' => $cart->updated_at?->toIso8601String(),
            'converted_at' => $cart->converted_at?->toIso8601String(),
            'status' => $this->status($cart),
            // From the joined visit row, so the address is not duplicated across
            // the two tables. Null when the cart sync was the first request this
            // browser ever made.
            'ip' => $cart->visitor_ip ?? null,
            'device' => $cart->visitor_device ?? null,
            // Null unless the cart converted, which is the whole point of the
            // abandoned filter: nothing to show for the rows it returns.
            'order_reference' => $cart->order?->reference,
            'items' => $cart->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_slug' => $item->product_slug,
                'product_sku' => $item->product_sku,
                'image_url' => $item->image_url,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
            ])->values(),
        ];
    }

    /**
     * Which of the three buckets a cart is in, recomputed rather than read from
     * a column.
     *
     * The two inputs are `converted_at` and how stale `updated_at` is, and a
     * stored status could disagree with both the moment the clock moves a cart
     * from open to abandoned. Deriving it here means the label and the filter
     * that produced the row can never disagree.
     */
    private function status(Cart $cart): string
    {
        if ($cart->converted_at !== null) {
            return 'converted';
        }

        return $cart->updated_at?->isBefore(now()->subHours(Cart::IDLE_HOURS))
            ? 'abandoned'
            : 'open';
    }

    /**
     * Headline numbers for the top of the panel.
     *
     * The conversion rate is deliberately NOT `converted / total carts`. That
     * denominator includes carts being filled right now, which would make the
     * rate dip every time someone opens the site and climb as those carts go
     * stale — a number that moves without a single sale being made. It is
     * computed over *resolved* carts instead: converted plus abandoned, i.e.
     * carts whose fate is already decided. The undecided count is reported
     * beside it as `in_progress` so the carts left out of the denominator are
     * visible rather than quietly absorbed.
     */
    private function summary(): array
    {
        $converted = Cart::query()->converted()->count();
        $abandoned = Cart::query()->abandoned()->count();
        $inProgress = Cart::query()->unconverted()->where(
            'updated_at',
            '>',
            now()->subHours(Cart::IDLE_HOURS)
        )->count();
        $resolved = $converted + $abandoned;

        return [
            'total' => Cart::query()->count(),
            'converted' => $converted,
            'abandoned' => $abandoned,
            'in_progress' => $inProgress,
            // Null rather than 0 when nothing is resolved yet: "0%" would read as
            // "every cart failed" when the honest answer is "no cart has had
            // time to resolve".
            'conversion_rate' => $resolved > 0 ? round($converted / $resolved * 100, 1) : null,
            'abandoned_value' => (float) Cart::query()->abandoned()->sum('subtotal'),
            'converted_value' => (float) Cart::query()->converted()->sum('subtotal'),
            'last_7d' => $this->countsSince(7),
            'last_30d' => $this->countsSince(30),
            // The carts worth calling the owner about: the ones that sat
            // untouched longest, and so are the most likely to be genuinely
            // lost rather than merely paused.
            'top_abandoned' => $this->topAbandoned(),
        ];
    }

    /**
     * @return array{abandoned: int, converted: int}
     */
    private function countsSince(int $days): array
    {
        $since = now()->subDays($days);

        return [
            'abandoned' => Cart::query()->abandoned()->where('updated_at', '>=', $since)->count(),
            'converted' => Cart::query()->converted()->where('converted_at', '>=', $since)->count(),
        ];
    }

    /**
     * The most valuable abandoned carts, grouped by product.
     *
     * Grouped rather than listed because the actionable question is not "which
     * basket was biggest" but "which perfume do people put in a basket and walk
     * away from" — a pricing, stock or photography problem, and one that shows
     * up as a pattern long before it shows up as a sale.
     *
     * Counted from the item rows rather than by walking carts, so the answer
     * costs one grouped query instead of a row per cart.
     *
     * @return array<int, array{product_id:int, name:string, slug:string, carts:int, units:int, value:float}>
     */
    private function topAbandoned(int $limit = 5): array
    {
        $cartIds = Cart::query()
            ->abandoned()
            ->select('id');

        return CartItem::query()
            ->whereIn('cart_id', $cartIds)
            ->groupBy('product_id', 'product_name', 'product_slug')
            ->selectRaw('product_id, product_name, product_slug')
            ->selectRaw('count(distinct cart_id) as carts')
            ->selectRaw('sum(quantity) as units')
            ->selectRaw('sum(subtotal) as value')
            // `value desc, carts desc` so two products of equal worth are
            // ordered by how many separate carts mentioned them, which is the
            // stronger signal.
            ->orderByRaw('sum(subtotal) desc, count(distinct cart_id) desc')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->product_name,
                'slug' => $row->product_slug,
                'carts' => (int) $row->carts,
                'units' => (int) $row->units,
                'value' => (float) $row->value,
            ])
            ->all();
    }
}
