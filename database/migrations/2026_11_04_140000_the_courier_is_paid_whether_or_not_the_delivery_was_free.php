<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery is a cost of the sale, not a pass-through.
 *
 * The courier is paid for every parcel, whether or not the customer was
 * charged for the delivery — a free delivery is paid out of the margin like
 * any other price. So an order keeps **the cost of the parcel** next to what
 * the customer was charged, and its benefit reads as the total minus the
 * goods minus the parcel.
 *
 * And the figure the panel shows can be corrected: an order can carry a
 * **set benefit**, the owner's own number, for everything the sums do not
 * know — the rate the supplier gave rather than typed, the courier that
 * charged more than the shop billed. A set figure replaces the calculation
 * rather than bending it, and taking it away gives the calculation back.
 *
 * The orders that were delivered before the parcel had a place of its own
 * are left without one: the price the courier charges for a city can change,
 * and quietly writing today's price into yesterday's orders would invent a
 * cost that was never recorded. An order with no figure is counted without
 * one, and the panel's correction is what puts a real number in its place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('courier_cost', 10, 2)->nullable()->after('shipping_cost');
            $table->decimal('benefit_override', 10, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_cost', 'benefit_override']);
        });
    }
};
