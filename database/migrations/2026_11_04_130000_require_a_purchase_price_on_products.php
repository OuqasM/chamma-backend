<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the purchase price a product's required figure.
 *
 * The benefit of an order is its total minus what the goods cost, so every
 * product has to know what the shop paid for it. A product without a purchase
 * price has no benefit to speak of, and a shop that cannot say what it earns
 * is trading blind.
 *
 * The rows that predate the rule are given **their own selling price** as the
 * purchase price: a zero margin, which is the conservative direction. It
 * understates the benefit rather than inventing one, and the owner replaces it
 * with the real figure from the panel — the product form now asks for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereNull('cost_price')
            ->orderBy('id')
            ->chunk(200, function ($products) {
                foreach ($products as $product) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['cost_price' => $product->price]);
                }
            });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable()->change();
        });
    }
};
