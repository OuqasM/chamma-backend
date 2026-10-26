<?php

namespace App\Concerns;

use App\Models\Product;
use Illuminate\Validation\Validator;

/**
 * A product sold in variants must be ordered in one of them.
 *
 * A cart line is nothing but a product id plus a free-form variant label the
 * browser sends along. Rules about the shape (`string`, `max:120`) are set in
 * the request; what a string cannot say is whether that label is one the shop
 * actually sells. This concern reads the real variant rows and decides:
 *
 * - A product with variants needs a variant on every line — "1 × Oud Royale"
 *   is not something there is a bottle of on the shelf.
 * - Whatever a line names must be one of that product's variants, matched
 *   case-insensitively so a "50ml" next to a stored "50 ml" does not quietly
 *   block an order that meant that bottle.
 * - A product without variants accepts no variant at all: a label there names
 *   a product the shop does not sell.
 *
 * Shared by checkout and the panel's typed orders on purpose: an order the
 * owner took by phone is not a lesser record free to keep a variant nobody
 * actually chose.
 */
trait ValidatesOrderVariants
{
    protected function validateVariantLines(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = collect($this->input('items', []))->filter(fn ($line) => is_array($line));

            if ($lines->isEmpty()) {
                return;
            }

            // One query for the whole cart, variants included. Lines that are
            // already rejected on `product_id` are skipped here, so this never
            // compounds onto a rule failure the shopper is already being told
            // about once.
            $products = Product::query()
                ->whereIn('id', $lines->pluck('product_id')->filter()->unique()->all())
                ->with('variants')
                ->get()
                ->keyBy('id');

            foreach ($lines as $index => $line) {
                $product = $products->get((int) ($line['product_id'] ?? 0));

                if (! $product) {
                    continue;
                }

                $names = $product->variants->pluck('name')->values();
                $chosen = trim((string) ($line['variant'] ?? ''));

                $found = $names->first(
                    fn (string $name) => mb_strtolower($name) === mb_strtolower($chosen)
                );

                // A product flagged `has_variants` but never given variant rows
                // keeps ordering like a plain product: the storefront renders no
                // picker for it, so there is nothing to demand.
                if ($names->isEmpty()) {
                    if ($chosen !== '') {
                        $this->variantError($validator, $index, $product, 'variant_unknown');
                    }

                    continue;
                }

                if ($found === null) {
                    $this->variantError(
                        $validator,
                        $index,
                        $product,
                        $chosen === '' ? 'variant_required' : 'variant_unknown',
                    );
                }
            }
        });
    }

    private function variantError(Validator $validator, int|string $index, Product $product, string $messageKey): void
    {
        $validator->errors()->add(
            "items.{$index}.variant",
            __('api.validation.'.$messageKey, ['product' => $product->name()]),
        );
    }
}
