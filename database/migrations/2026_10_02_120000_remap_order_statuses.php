<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Narrows the order lifecycle to what the shop actually uses.
 *
 * The enum used to offer six states, but `preparing` and `shipped` were never
 * worked: an order is taken, confirmed with the customer, and delivered. The
 * two middle states only invited the owner to park an order somewhere it then
 * sat, so they are folded into `confirmed`, which is the real "we are on it".
 *
 * `pending` is renamed to `new`. Same meaning, but the label the owner reads
 * now matches the one state that genuinely means "nothing done yet".
 *
 * Existing rows are remapped rather than left dangling: an enum cast throws on
 * a value that no longer exists a case for, which would have taken the whole
 * admin order list and dashboard down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('status', 'pending')->update(['status' => 'new']);
        DB::table('orders')->whereIn('status', ['preparing', 'shipped'])->update(['status' => 'confirmed']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->change();
        });
    }

    public function down(): void
    {
        // `new` goes back to `pending`; the folded `confirmed` rows cannot be
        // told apart from orders that were always confirmed, so they stay put.
        DB::table('orders')->where('status', 'new')->update(['status' => 'pending']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }
};
