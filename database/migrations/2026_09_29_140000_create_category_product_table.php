<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product belongs to more than one category, so the single category_id moves
 * to a pivot.
 *
 * The copy and the column drop have to live in one migration: between them the
 * old assignment would have nowhere to live, so a half-applied deploy would lose
 * every product's category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
            // The category pages walk this in the other direction, from a
            // category to its products.
            $table->index('product_id');
        });

        // Chunked so the copy never has to hold the whole products table in
        // memory on a first run against a real catalogue.
        DB::table('products')
            ->whereNotNull('category_id')
            ->select(['id', 'category_id'])
            ->chunkById(500, function ($products) {
                $rows = [];

                foreach ($products as $product) {
                    $rows[] = [
                        'product_id' => $product->id,
                        'category_id' => $product->category_id,
                    ];
                }

                if ($rows !== []) {
                    DB::table('category_product')->insert($rows);
                }
            });

        Schema::table('products', function (Blueprint $table) {
            // The index goes first: SQLite refuses to drop a column that one
            // still points at.
            $table->dropIndex(['is_active', 'category_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['is_active', 'category_id']);
        });

        // A single column cannot hold several categories, so the rollback keeps
        // the lowest category id each product had and discards the rest rather
        // than pretending the many-to-many fitted back into one field.
        DB::table('category_product')
            ->orderBy('category_id')
            ->get()
            ->groupBy('product_id')
            ->each(function ($categories, $productId) {
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['category_id' => $categories->min('category_id')]);
            });

        Schema::dropIfExists('category_product');
    }
};
