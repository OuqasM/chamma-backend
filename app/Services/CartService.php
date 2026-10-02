<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records what is in a shopper's cart, so a cart that is never checked out is
 * still visible afterwards.
 *
 * The storefront cart lives in `localStorage`, which means it is a private
 * browser record: it leaves the machine only when the shopper submits an order.
 * An abandoned cart is by definition one that is never submitted, so this is the
 * only way it can ever become visible to the shop.
 *
 * The whole cart is replaced on each call rather than diffed. A cart is small —
 * a handful of lines at most — and a full replace cannot drift out of step with
 * what the browser is showing, which is the failure mode that matters here: a
 * report that says someone left four items when they left two is worse than no
 * report at all. `cart_items` also carries a unique index on
 * (cart_id, product_id), so two overlapping syncs cannot leave duplicate lines.
 */
class CartService
{
    /**
     * Lines beyond this are refused rather than stored.
     *
     * A real cart is a handful of perfumes. A cap keeps one scripted request
     * from writing a cart of 50,000 lines that then has to be read back by the
     * report, and it bounds the payload the endpoint will accept.
     */
    public const MAX_LINES = 30;

    /**
     * Quantity per line, same reasoning. A shopper buying 40 of a 3ml decant is
     * either a wholesaler or a mistake, and either way the order flow applies its
     * own stock check.
     */
    public const MAX_QUANTITY = 99;

    /**
     * Replaces the recorded cart for a visitor with the given lines.
     *
     * `lines` is the cart as the browser currently holds it: product_id and
     * quantity, which is all the client is trusted to know. Names, prices and
     * images are read from the database here, because a client that could set
     * its own price would make the report's totals meaningless.
     *
     * Products that cannot be sold right now are dropped rather than rejected.
     * A shopper with an out-of-stock item still in their cart from a week ago
     * should get a working cart back, not a validation error on every quantity
     * change; the order flow is where an unavailable product must fail, and
     * OrderService::place already refuses it.
     *
     * @param  array<int, array{product_id: int|string, quantity?: int|string}>  $lines
     * @param  string  $visitorId  The identity token from the request cookie.
     * @param  string  $locale  The language the cart is being read in, which
     *                          decides the snapshot names.
     */
    public function sync(string $visitorId, array $lines, string $locale): Cart
    {
        // A duplicate product_id in one payload is two lines the browser does not
        // actually show. Grouping by product and summing inside each group
        // records the cart the shopper is looking at rather than a doubled one:
        // a stale client sending "oud x1, oud x1" leaves one line of two.
        //
        // Built from `groupBy`/`sum` rather than a `sumBy` helper, which this
        // Laravel version's Collection does not have — calling it would be a
        // fatal, and the controller's catch turns a fatal into a silent 204,
        // which is exactly how a cart arrives never being written at all.
        //
        // The id is filtered before grouping, not after: a line naming product 0
        // still creates a group under key 0, and only dropping it afterwards
        // would leave a spurious entry in `keys()`.
        $quantities = collect($lines)
            ->filter(fn ($line) => isset($line['product_id']) && (int) $line['product_id'] > 0)
            ->groupBy(fn ($line) => (int) $line['product_id'])
            ->map(fn ($group) => $group->sum(
                fn ($line) => max(1, (int) ($line['quantity'] ?? 1))
            ));

        // Summing can push a product past the cap, so the clamp happens after the
        // sum rather than before it.
        $quantities = $quantities->map(fn (int $quantity) => min(self::MAX_QUANTITY, $quantity));

        $ids = $quantities->keys()->all();

        if ($ids !== [] && count($ids) > self::MAX_LINES) {
            $ids = array_slice($ids, 0, self::MAX_LINES);
            $quantities = $quantities->only($ids);
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $ids)
            // `translations` for the locale-aware name and slug, `images` for the
            // thumbnail. Eager loaded because both are used per line and would
            // otherwise be a query each.
            ->with(['translations', 'images'])
            ->get()
            // Keyed by id, so the loop below cannot pick the wrong product when
            // the ids array order differs from the result order.
            ->keyBy('id');

        $items = [];
        $subtotal = 0.0;

        foreach ($quantities as $productId => $quantity) {
            /** @var ?Product $product */
            $product = $products->get($productId);

            if (! $product || ! $product->is_active) {
                continue;
            }

            $unitPrice = (float) $product->price;
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            $items[] = [
                'product_id' => $product->id,
                // `name()` walks the translation fallback chain, so a cart filled
                // in Arabic of a product with no Arabic translation still shows
                // the French name rather than an empty line. The SKU is the last
                // resort, matching OrderService::nameIn.
                'product_name' => $product->name($locale) ?: (string) $product->sku,
                'product_slug' => $product->slug($locale),
                'product_sku' => $product->sku,
                'product_image' => $product->primaryImage()?->path,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);

        $units = array_sum($quantities->all());

        return DB::transaction(function () use ($visitorId, $locale, $items, $subtotal, $units) {
            /** @var Cart $cart */
            $cart = Cart::query()->firstOrNew(['visitor_id' => $visitorId]);

            $cart->locale = $locale;
            $cart->subtotal = $subtotal;
            // Units, not lines. A cart of two oud is "3 items" to the shopper
            // who left it behind, and the report reads this as a count of things
            // left rather than a count of table rows.
            $cart->items_count = $units;
            // A cart being emptied is still a change worth counting, so this
            // increments on every sync including one that leaves zero lines. It
            // is read as "how much did this shopper fiddle with it", which is
            // the intent signal the report cannot get from the order side.
            $cart->updates_count = (int) $cart->updates_count + 1;
            $cart->save();

            // Wholesale replace. `delete()` then `createMany()` inside the same
            // transaction, so a reader never sees a cart with some of its lines
            // missing, and a failure part-way leaves the previous cart intact.
            $cart->items()->delete();
            $cart->items()->createMany($items);

            return $cart->load('items');
        });
    }

    /**
     * Marks the cart a visitor checked out from, and points it at the order.
     *
     * Called after the order is committed rather than inside its transaction.
     * A cart that is never linked is only a report row; an order that fails to
     * write because a cart could not be linked would be a lost sale, so the
     * order is the thing that is allowed to fail loudly and the link is not.
     *
     * `firstOrNew` because a shopper can reach checkout without a prior cart
     * sync — a saved link, a stale `localStorage`, or the sync request having
     * failed — and those orders still deserve a cart row so the conversion rate
     * is not understated.
     */
    public function markConverted(string $visitorId, int $orderId, string $locale): void
    {
        /** @var Cart $cart */
        $cart = Cart::query()->firstOrNew(['visitor_id' => $visitorId]);

        $cart->locale = $locale;
        $cart->order_id = $orderId;
        // `converted_at` is the whole truth of "this became an order". Setting it
        // again on a second order from the same visitor is not a bug to guard
        // against: one person buying twice is one cart that converted, and a
        // second row would double-count the cart in the summary.
        $cart->converted_at = $cart->converted_at ?? now();
        $cart->save();
    }
}
