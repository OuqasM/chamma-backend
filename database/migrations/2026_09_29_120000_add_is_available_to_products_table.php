<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the two questions the single "available" switch used to answer.
 *
 * `is_active` decides whether the product appears on the storefront at all, and
 * stays the admin's unpublish control. `is_available` is independent of it and
 * only decides whether the product reads as buyable or is labelled out of
 * stock, so a product can stay on the shelf while temporarily unavailable.
 *
 * Existing products are all treated as available, which is what the previous
 * `is_active && stock > 0` rule reported for everything already on sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_available');
        });
    }
};
