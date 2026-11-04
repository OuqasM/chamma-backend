<?php

use App\Models\Order;
use App\Services\CustomerService;
use App\Services\ShippingZoneService;
use Illuminate\Database\Migrations\Migration;

/**
 * Turns the orders the shop already has into customers.
 *
 * A customer is written by an order, and until now the shop's orders predate
 * the customer table: they were sales, but there was no record of *who* they
 * were sold to. So the panel's customer list would have started empty while
 * the order list was full — the exact "we have no customers" reading that is
 * wrong.
 *
 * The phone is the identity, and every order carries it in the reduced form
 * (the column the previous migration added and backfilled), so an order is
 * matched to its customer the same way a new order will be. One person who
 * ordered three times is one customer with three orders, not three customers.
 *
 * The last order wins on the name, the city and the address, exactly as it
 * does for a new order: a customer who moved is at their new address now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $customers = app(CustomerService::class);
        $zones = app(ShippingZoneService::class);

        // Keyed by id rather than offset: the loop writes to another table, but
        // keying by id keeps the page stable whatever else is going on.
        Order::query()
            ->whereNotNull('phone_normalised')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($customers, $zones) {
                foreach ($orders as $order) {
                    $customers->record(
                        (string) $order->customer_name,
                        (string) $order->phone_normalised,
                        // The carrier's own spelling of the city, which is what
                        // the panel's city list holds. An older order may have
                        // been written with the readable spelling ("Rabat")
                        // rather than the carrier's ("RABAT"), and a customer
                        // whose city is not in that list cannot be prefilled
                        // into the form — the save would be refused for a field
                        // the owner cannot see.
                        $zones->canonical((string) $order->city) ?? (string) $order->city,
                        (string) $order->address ?: null,
                    );
                }
            });
    }

    public function down(): void
    {
        // The customers this migration created are indistinguishable from the
        // ones the shop earned afterwards, so there is nothing to undo without
        // deleting people the shop has genuinely sold to. Left alone.
    }
};
