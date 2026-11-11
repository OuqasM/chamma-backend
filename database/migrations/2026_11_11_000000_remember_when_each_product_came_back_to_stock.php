<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moment a product came back to the shelf is worth remembering.
 *
 * The owner marks a sold-out perfume available again and the shop wants it
 * seen: first on the storefront's listing, first in the panel's products list.
 * Ordering on `updated_at` cannot say that — an edit to any row's copy would
 * push the bottle that just came back down the list. This column is written
 * only on the unavailable -> available edge (the same edge the restock alert
 * fires on), so the listings can sort on the restock itself.
 *
 * Existing products get NULL: they have never come back, and a NULL sorts
 * after every restock on a descending order in both MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('restocked_at')->nullable()->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('restocked_at');
        });
    }
};
