<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers waiting to be told a product is back in stock.
 *
 * One row per person per product. The unique index on (product_id, phone) is
 * the mechanism, not just a guard: a customer who signs up twice must not
 * become two people for the owner to call, so the second submission updates the
 * first row instead of inserting another.
 *
 * The phone is stored twice on purpose. `phone` keeps what the customer typed
 * so the panel shows them the number they recognise, and `phone_normalised` is
 * the digits-only form the unique index and the `wa.me` link use. Comparing raw
 * input would treat "0612345678" and "06 12 34 56 78" as two people.
 *
 * `name` is collected alongside the number, and is required: a number on its own
 * is a dial string, and the owner opening an unknown number mid-shift cannot
 * tell a regular from someone who called once. The name is what makes the
 * greeting possible.
 *
 * This column was added before the migration was ever run outside the test
 * suite, so it is part of the create rather than a second migration — a table
 * that exists only in `php artisan test` does not need a patch to gain a column
 * it never had anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();

            // On delete cascade rather than restrict: removing a product should
            // take its waiting list with it. Those rows are only useful while
            // the product exists, and orphaned phone numbers about a product
            // that is gone are just personal data nobody asked to keep.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('phone', 30);
            $table->string('phone_normalised', 20);

            // Required, not nullable. A number with no name is a dial string,
            // and the owner working down the list cannot tell a regular from
            // someone who rang once. Not stored split into first/last the way
            // orders are: nobody addresses an envelope here, and the owner
            // reads this column whole to say "Amina? It's Chamma".
            $table->string('name', 120);

            // Which language they were waiting in, so the store can reply in it.
            $table->string('locale', 5)->default('fr');

            // When the owner told them, and how: an entry that has been acted
            // on is a call already made, and stays on the list as a record that
            // this person was contacted rather than silently vanishing.
            $table->timestamp('notified_at')->nullable();
            $table->string('notified_channel', 20)->nullable();

            $table->timestamps();

            // The merge rule above, enforced by the database rather than by
            // remembering to check before inserting.
            $table->unique(['product_id', 'phone_normalised']);

            // The panel's default view is the outstanding entries, newest
            // first, so that is the access path worth indexing.
            $table->index(['product_id', 'notified_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
