<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots what the goods cost on every order line.
 *
 * An order line already keeps the price it was sold at, so the order stays
 * truthful after the catalogue changes. The same has to be true of what the
 * shop paid for the unit: the benefit of an order is its total minus the
 * purchase price of what is in it, and a benefit computed from today's
 * purchase price would rewrite history every time a price changed.
 *
 * The rows that predate this column are backfilled from the products' current
 * purchase price. It is the best available answer for them, and the owner is
 * filling the rest in — the purchase price is now required on every product.
 *
 * It runs **after** the migration that gives the products their purchase
 * price: the backfill copies that figure, and a product without one would
 * give the line a cost of zero — which would read as a benefit of the whole
 * turnover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('unit_price');
        });

        // Backfilled in PHP rather than in SQL: the product is on the other
        // side of a join, and a chunked loop keeps the query off the join.
        DB::table('order_items')
            ->whereNull('unit_cost')
            ->orderBy('id')
            ->chunk(200, function ($items) {
                $costs = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                    ->pluck('cost_price', 'id');

                foreach ($items as $item) {
                    $cost = $costs[$item->product_id] ?? null;

                    if ($cost === null) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->update(['unit_cost' => $cost]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
