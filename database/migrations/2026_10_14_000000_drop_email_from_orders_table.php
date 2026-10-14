<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the customer `email` column from orders.
 *
 * It was optional and, in practice, never used: the storehands an order over
 * on WhatsApp and calls the customer back by phone, so the address only ever
 * held a value on a handful of seeded rows. A nullable `varchar` on every
 * order costs a length byte and a null bit per row for a field nothing reads,
 * so the column goes.
 *
 * `down()` restores it as nullable. No back-fill is possible or wanted: the
 * addresses are simply not recoverable once dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('email')->nullable();
        });
    }
};
