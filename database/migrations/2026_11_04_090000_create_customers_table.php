<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people the shop sells to.
 *
 * One row per person, keyed by their phone number. The number is the identity:
 * it is how the shop recognises a returning customer, how the panel finds them
 * again when a new order comes in, and how the same person is not recorded
 * three times because they wrote their number in three different shapes.
 *
 * The phone is stored twice, like the waiting list's. `phone` keeps what was
 * typed, so the panel shows a number the customer recognises, and
 * `phone_normalised` is the one form the unique index and the search use.
 * Comparing the raw input would treat "0612345678" and "06 12 34 56 78" as
 * two people.
 *
 * The name, the city and the address are the last ones seen on an order. They
 * are what the panel fills in when the owner types a number it already knows:
 * a regular should not have to be asked where they live twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);

            $table->string('phone', 30);
            $table->string('phone_normalised', 20);

            // Nullable: a customer can be created from a phone number alone —
            // the waiting list asks for a name, but a customer row may be
            // written before the rest of the details are known.
            $table->string('city', 100)->nullable();
            $table->string('address', 255)->nullable();

            $table->timestamps();

            // One person, one row. This is what stops the same customer from
            // becoming three customers because they wrote their number three
            // ways.
            $table->unique('phone_normalised');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
