<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carts as they stood, so an order that was never placed is still visible.
 *
 * The storefront cart lives in `localStorage` and reaches the server only when
 * the shopper submits it, which is the whole reason this table exists: until now
 * a cart that was filled and then abandoned left no record at all, so the panel
 * could not distinguish "browsed and left" from "never interested". Only a
 * completed `orders` row was ever written.
 *
 * The identity is the same `visitor_id` token the visitor tracker already issues
 * in its first-party cookie, and there is deliberately no second cookie and no
 * new identifier. That token is an anonymous 128-bit random value: it says "this
 * is the same browser as last week", which is the only thing needed to tell an
 * abandoned cart from a fresh one, and it is not derived from anything about the
 * person or their machine.
 *
 * What is NOT stored here, on purpose:
 *
 * - No email, name, or phone. A cart belongs to someone who has not bought, so
 *   adding their details would mean holding personal data about people who
 *   explicitly did not hand it over. Contact details arrive with the order, and
 *   the two tables are joined through `orders.cart_id` only once an order exists.
 * - No IP address. The `visits` table already records one per `visitor_id`, and
 *   duplicating it here would give the same data a second home with no second
 *   purpose. The report reads the address by joining `visits`, so it is not
 *   stored twice and is not carried on rows that outlive the visit.
 *
 * The address of a visitor is unreliable as an identity — a mobile carrier hands
 * one IP to thousands of people, and one person's address changes through the
 * day — which is exactly why the cart is keyed on the cookie and not the IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            // The identity, and the column the sync upsert matches on. Same
            // 32-char lowercase hex format as `visits.visitor_id`, so one token
            // resolves a visitor on both tables.
            $table->string('visitor_id', 32)->unique();

            // The language the cart was filled in. It decides the product names
            // and currency shown in the report, and a cart abandoned mid-session
            // is only interpretable next to the language it was written in.
            $table->string('locale', 5)->default('fr');

            // Cart value at the moment of the last change, kept denormalised so
            // the report can sort and total without loading every line item.
            // `subtotal` only: shipping depends on a city the shopper has not
            // typed yet, so a total here would be a number that never existed.
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);

            // How many times the shopper changed the cart. A cart touched once
            // and a cart repeatedly edited are different signals of intent, and
            // the report is much more useful with that distinction available.
            $table->unsignedInteger('updates_count')->default(1);

            // Set when an order is placed from this cart. The ABSENCE of this
            // timestamp is what "abandoned" means: there is no `status` column
            // to disagree with it, and no third state that can drift out of
            // sync with the order table the way a cached flag would.
            $table->timestamp('converted_at')->nullable();

            // The order this became, which is the only thing that turns a cart
            // row into something that identifies a person.
            //
            // The link points outward, cart -> order, rather than the usual
            // order -> cart. The report is read cart-first: it walks carts and
            // wants the order reference for the ones that converted. No column
            // is added to `orders` for it, which also means a cart can be
            // deleted by the retention command without a dangling reference on
            // the order side, and an order deleted by hand leaves the cart row
            // harmless.
            //
            // No foreign key constraint, deliberately: the retention window will
            // remove carts long after orders are kept, and a constraint here
            // would make the purge fail on every cart whose order is still
            // around.
            $table->unsignedBigInteger('order_id')->nullable()->index();

            $table->timestamps();

            // The report's two access paths: the default list is abandoned
            // carts newest-first, and the summary counts by `converted_at`.
            $table->index('converted_at');
            $table->index('updated_at');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            // A cart's lines are replaced wholesale on every sync, so this is
            // the only relationship that needs a constraint. The product side is
            // a plain id because a line has to survive the product being
            // deleted — an abandoned cart is more useful as a record of what
            // someone wanted than as an error, and the name below survives too.
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('product_id')->index();

            // Snapshots, not a join back to the product. An abandoned-cart
            // report answers "what did they leave, and what was it worth when
            // they left it"; re-pricing a product next month must not silently
            // rewrite the value of a cart from last Tuesday. The live price is
            // available by joining `products` when the report wants a
            // comparison.
            $table->string('product_name');
            $table->string('product_slug');
            $table->string('product_sku')->nullable();
            $table->string('product_image')->nullable();

            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 10, 2);

            $table->timestamps();

            // One line per product within a cart. Enforced in the database
            // rather than left to the service, so two concurrent syncs from one
            // shopper cannot leave duplicate lines for the same perfume.
            $table->unique(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
