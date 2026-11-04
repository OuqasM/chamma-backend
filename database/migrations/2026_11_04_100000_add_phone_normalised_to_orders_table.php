<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the orders the phone number in the one form the shop agrees on.
 *
 * The customers are keyed by their number, and "a customer's orders" has to be
 * a question the database can answer. An order keeps the number as it was
 * typed — a website order stores what the customer typed, an order typed in the
 * panel stores the reduced one — so joining the two tables on the raw column
 * would find some of a customer's orders and not others.
 *
 * The column is nullable and the rows that predate it are backfilled here, so
 * a customer whose first order was placed before this migration still shows
 * that order. A row whose phone cannot be reduced stays null: there is no
 * honest value to put there, and an order is not lost by having no reduced
 * number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('phone_normalised', 20)->nullable()->after('phone');
        });

        // Backfilled in PHP rather than in SQL: the reduction is the one the
        // application uses everywhere else, and a second implementation of it
        // in a migration would be a second thing to keep in step.
        DB::table('orders')
            ->whereNull('phone_normalised')
            ->orderBy('id')
            ->chunk(200, function ($orders) {
                foreach ($orders as $order) {
                    $normalised = PhoneNumber::normalise((string) $order->phone);

                    if ($normalised === null) {
                        continue;
                    }

                    // Straight to the table, so the order's `updated_at` keeps
                    // saying when the order itself last changed. A migration
                    // that rewrote every order's timestamp would make the
                    // panel's "placed at" dates lie.
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['phone_normalised' => $normalised]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('phone_normalised');
        });
    }
};
