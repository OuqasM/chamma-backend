<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product's barcode, as printed on the packaging.
 *
 * Google Merchant Center (the Shopping tab) matches products against its own
 * catalogue by GTIN, and without one the listing sinks behind every shop that
 * does carry it. Stored as a string rather than a number so a UPC-12's leading
 * zero survives, and capped at 14 (GTIN-14) with the length set checked in the
 * form request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('gtin', 14)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('gtin');
        });
    }
};
