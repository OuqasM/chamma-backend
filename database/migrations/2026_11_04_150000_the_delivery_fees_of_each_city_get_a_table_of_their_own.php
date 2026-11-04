<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery fee of each city gets a table of its own.
 *
 * The price of delivering to a city is a fact about the shop, not a stray
 * setting: it is what the customer is charged, what the courier is paid, and
 * what the benefit of every order to that city is settled against. It
 * belonged in the settings table only because it was quicker to write there —
 * a row per city, under a prefix, read back with a `LIKE` and cast to a
 * number. It now says what it is: one row per city.
 *
 * The prices that were already kept are carried over, and the settings they
 * lived in are cleared: one price per city, in one place, read by one service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_fees', function (Blueprint $table) {
            $table->id();
            // The city, as the panel spells it: the same string the order
            // carries, so the fee and the order join without a translation.
            $table->string('city')->unique();
            // What a parcel to that city costs the shop.
            $table->decimal('fee', 10, 2);
            $table->timestamps();
        });

        foreach (DB::table('settings')->where('key', 'like', 'shipping_fee.%')->get() as $setting) {
            $city = substr((string) $setting->key, strlen('shipping_fee.'));

            if ($city === '' || $setting->value === null || $setting->value === '') {
                continue;
            }

            DB::table('delivery_fees')->insert([
                'city' => $city,
                'fee' => (float) $setting->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The prices live in one place now.
        DB::table('settings')->where('key', 'like', 'shipping_fee.%')->delete();
    }

    public function down(): void
    {
        foreach (DB::table('delivery_fees')->get() as $fee) {
            DB::table('settings')->insert([
                'key' => 'shipping_fee.'.$fee->city,
                'value' => (string) $fee->fee,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('delivery_fees');
    }
};
