<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer reviews, as screenshots of WhatsApp conversations.
 *
 * One row per screenshot. There is no text column and no translation table on
 * purpose: the conversation is the copy, and it reads the same in every
 * language — the carousel shows the picture the customer sent, not a
 * paraphrase of it.
 *
 * `image` holds the stored path ('uploads/…' on the storefront disk), exactly
 * like the brands' logo and the categories' image: the panel uploads the file
 * through POST /api/admin/uploads first and then hands the path here, so the
 * same optimizer and the same document root serve these as serve every other
 * photograph.
 *
 * `position` follows the brands' convention: edited numbers lead, rows the
 * owner never numbered — the column default, 0 — append in creation order.
 *
 * The table is `review_screenshots` rather than `reviews` because a
 * `reviews` table from an October scaffold (product_id, rating, body,
 * author_name) already exists in every database that ran
 * `2026_10_02_090000_create_reviews_table` — a migration since deleted from
 * the tree, so its table survives as an orphan no code reads. Creating
 * `reviews` would have collided with it on every one of those databases;
 * naming the new table for what a row actually is costs nothing and loses
 * the collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_screenshots', function (Blueprint $table) {
            $table->id();

            $table->string('image');

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The storefront's only read is the active list in the owner's
            // order; the panel's list is the same order including inactive
            // rows. One composite index covers both.
            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_screenshots');
    }
};
