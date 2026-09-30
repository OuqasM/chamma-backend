<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the hand-maintained `is_new` flag.
 *
 * It never earned its keep. Anyone could tick it and leave it set for years, and
 * it was ANDed with `created_at >= now() - 45 days` in `scopeNewArrivals`, so a
 * ticked-but-old product was excluded while an old-but-recently-created one
 * qualified — the flag and the date disagreed and the flag usually lost.
 *
 * "New" is now simply `created_at DESC`: true by construction, needs no admin
 * field, and cannot go stale. The storefront's `?is_new=1` filter is gone with
 * it; those links now use `?sort=newest`, which was already the same ordering.
 *
 * `down()` restores the column with `false` for every existing row rather than
 * back-filling from `created_at`. The flag was advisory and the fallback scope
 * covered recent rows anyway, so inventing a value for history would fabricate
 * decisions nobody made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Dropped explicitly: on MySQL an index over a dropped column has to
            // go first, and leaving the name to implicit cleanup differs by driver.
            $table->dropIndex(['is_active', 'is_new']);
            $table->dropColumn('is_new');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_new')->default(false);
            $table->index(['is_active', 'is_new']);
        });
    }
};
