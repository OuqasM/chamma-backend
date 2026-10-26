<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a guest cart into a Cash-on-Delivery order.
 *
 * Prices are re-read from the database (never trusted from the client), stock
 * is decremented atomically, and every line keeps a snapshot of the product
 * name/price so the order stays truthful after the catalogue changes.
 */
class OrderService
{
    public function __construct(private readonly ShippingService $shipping) {}

    /**
     * Cart subtotal for the live delivery quote.
     *
     * Prices come from the database, never from the request: a client-supplied
     * subtotal would let a shopper fake the free-shipping verdict. No locking
     * and no stock mutation — this is a read-only preview of place().
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     */
    public function subtotal(array $lines): float
    {
        $ids = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all();

        if ($ids === []) {
            return 0.0;
        }

        $prices = Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('price', 'id');

        $subtotal = 0.0;

        foreach ($lines as $line) {
            $price = $prices->get((int) $line['product_id']);

            // Unavailable products contribute nothing; place() is what
            // actually rejects the order.
            if ($price === null) {
                continue;
            }

            $subtotal += (float) $price * max(1, (int) $line['quantity']);
        }

        return round($subtotal, 2);
    }

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     * @param  array<string, mixed>  $customer
     */
    public function place(array $lines, array $customer, string $locale): Order
    {
        return DB::transaction(function () use ($lines, $customer, $locale) {
            $ids = collect($lines)->pluck('product_id')->unique()->all();

            /** @var Collection<int, Product> $products */
            $products = Product::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->with(['translations', 'images', 'brand.translations', 'variants'])
                ->get()
                ->keyBy('id');

            $items = [];
            $subtotal = 0.0;

            foreach ($lines as $line) {
                /** @var ?Product $product */
                $product = $products->get((int) $line['product_id']);

                if (! $product || ! $product->is_active) {
                    throw new \DomainException(__('api.errors.product_unavailable'));
                }

                $quantity = max(1, (int) $line['quantity']);

                if ($product->stock < $quantity) {
                    throw new \DomainException(__('api.errors.insufficient_stock', [
                        'product' => $product->name($locale),
                    ]));
                }

                $unitPrice = (float) $product->price;
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $items[] = [
                    'product_id' => $product->id,
                    'product_name' => $this->nameIn($product, $locale),
                    'product_slug' => $product->slug($locale),
                    'product_sku' => $product->sku,
                    'variant' => $this->variantFor($product, $line['variant'] ?? null, $locale),
                    'product_image' => $product->primaryImage()?->path,
                    'locale' => $locale,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineTotal,
                ];

                $product->decrement('stock', $quantity);
            }

            $subtotal = round($subtotal, 2);
            $shippingCost = $this->shipping->cost($subtotal, (string) $customer['city']);

            // The shopper types one name; the columns stay split so the admin
            // list and any future invoice keep their first/last columns.
            [$firstName, $lastName] = $this->splitName((string) $customer['name']);

            $order = Order::create([
                'reference' => $this->reference(),
                'locale' => $locale,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'customer_name' => trim($customer['name']),
                'phone' => $customer['phone'],
                'city' => $customer['city'],
                'address' => $customer['address'],
                'notes' => $customer['notes'] ?? null,
                'payment_method' => $customer['payment_method'],
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'total' => round($subtotal + $shippingCost, 2),
                'status' => OrderStatus::New,
            ]);

            $order->items()->createMany($items);

            return $order->load('items');
        });
    }

    /**
     * Human friendly, non guessable order reference: CP-2609-4821.
     */
    public function reference(): string
    {
        do {
            $reference = sprintf(
                'CP-%s-%04d',
                now()->format('ym'),
                random_int(1000, 9999)
            );
        } while (Order::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * "Ahmed Ben Ali" -> ["Ahmed", "Ben Ali"]; a one-word name keeps the second
     * column empty rather than duplicating the first.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    /**
     * Prefer an existing translation, never an empty string in a receipt.
     */
    private function nameIn(Product $product, string $locale): string
    {
        $translation = $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', config('chamma.default_locale'))
            ?? $product->translations->first();

        return (string) ($translation?->name ?: $product->sku);
    }

    /**
     * What one order line says about which bottle it is.
     *
     * The validation layer has already checked the variant (see
     * `ValidatesOrderVariants`), but re-deciding here makes the service safe for
     * every caller, not only the two requests — the rules cannot drift between
     * where they are validated and where they are written:
     *
     * - a product without variants keeps its line variant-free, whatever text
     *   the browser happened to attach;
     * - a product with variants must be ordered in one of them;
     * - and the stored name is the catalogue's spelling rather than the
     *   browser's, so "50ml" next to a stored "50 ml" does not multiply the
     *   number of sizes reporting keeps finding.
     */
    private function variantFor(Product $product, mixed $chosen, string $locale): ?string
    {
        $names = $product->variants->pluck('name');

        if ($names->isEmpty()) {
            return null;
        }

        $chosen = trim((string) $chosen);

        if ($chosen === '') {
            throw new \DomainException(__('api.errors.variant_required', [
                'product' => $this->nameIn($product, $locale),
            ]));
        }

        $found = $names->first(
            fn (string $name) => mb_strtolower(trim($name)) === mb_strtolower($chosen)
        );

        if ($found === null) {
            throw new \DomainException(__('api.errors.variant_unknown', [
                'product' => $this->nameIn($product, $locale),
            ]));
        }

        return $found;
    }
}
