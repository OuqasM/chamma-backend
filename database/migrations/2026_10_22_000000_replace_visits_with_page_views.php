<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the one-row-per-visitor `visits` table into an append-only
 * `page_views` log.
 *
 * The old table kept one line per visitor and refreshed it on every page
 * view, which forced the writer into a four-statement dance (existence check,
 * upsert, counter increment, re-read) to stay consistent when two requests
 * from the same visitor landed together. The log removes that dance: a page
 * view is one immutable row, parallel requests each append their own, and
 * every total the panel shows is a query over the log rather than state a
 * write has to protect.
 *
 * The one cost is the old shape's lack of history, which the copy below
 * inherits: a visitor row knew `first_seen`, `last_seen` and a running
 * `visits_count`, but not *which* pages the repeats landed on, so it cannot
 * be split back into the individual views it summarized. Each old row
 * therefore becomes a single event carrying its last known state and its
 * `last_seen`, and the per-visitor counters reset from there. Nothing older
 * than this migration is recoverable on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            // One append-only row per storefront page view. There is no
            // unique constraint because nothing here claims to be unique, and
            // `visitor_id` repeats on purpose: distinct identities are a
            // count over the log, not a distinct key.
            $table->id();

            // The identity: a random 32-char token issued by the server and
            // read back from the visitor's cookie. Not a fingerprint — see
            // `VisitorTracker` for why that is deliberate.
            $table->string('visitor_id', 32)->index();

            // Network and device, self-reported by the request. `ip` is
            // nullable because it can be absent behind some proxies.
            $table->string('ip', 45)->nullable()->index();
            $table->string('device', 20)->default('other');
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();

            // What they were looking at, and where they came from. Both are
            // truncated to column width by the service rather than trusted.
            $table->string('path', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('locale', 5)->default('fr');

            // When the view happened. The only timestamp the log needs: rows
            // are never revised, so there is no `updated_at` to maintain.
            // `created_at` is also the column the scheduled prune reads.
            $table->timestamp('created_at')->index();

            // The panel groups by address and reads the newest rows, so `ip`,
            // `created_at` and `visitor_id` carry indexes. `device` relies on
            // a scan, which is fine for a log the prune keeps a year of; `id`
            // needs no help for the "newest first" tiebreak.
        });

        // Carry the previous table's rows across before dropping it, so the
        // panel keeps its visitors instead of starting from silence.
        if (Schema::hasTable('visits')) {
            // One event per old row. The old `visits_count` cannot be fanned
            // out into missing events: only their aggregate survived, so the
            // history continues from here as one view per visitor.
            DB::statement(
                'insert into page_views'.
                ' (visitor_id, ip, device, browser, os, path, referrer, locale, created_at)'.
                ' select visitor_id, ip, device, browser, os, path, referrer, locale, last_seen from visits',
            );

            Schema::drop('visits');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');

        if (! Schema::hasTable('visits')) {
            Schema::create('visits', function (Blueprint $table) {
                $table->id();

                $table->string('visitor_id', 32)->unique();

                $table->string('ip', 45)->nullable();
                $table->string('device', 20)->default('other');
                $table->string('browser', 40)->nullable();
                $table->string('os', 40)->nullable();
                $table->string('path', 255)->nullable();
                $table->string('referrer', 255)->nullable();
                $table->string('locale', 5)->default('fr');
                $table->unsignedInteger('visits_count')->default(1);
                $table->timestamp('first_seen');
                $table->timestamp('last_seen');
                $table->timestamps();

                $table->index('last_seen');
                $table->index('device');
            });
        }
    }
};
