<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per visitor, updated in place on every return visit.
 *
 * There is no MAC address column because a web server cannot read one: the
 * browser sandbox has no access to the network hardware layer, so any such
 * value would be an IP address under a misleading name. `visitor_id` is a
 * random token issued by the server and stored in the visitor's own cookie, so
 * it survives an IP change and is not derived from anything about the machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            // The identity. A random 32-char token, not a fingerprint: it is
            // issued by the server and read back from the visitor's cookie.
            // Unique, because it is the column the upsert matches on.
            $table->string('visitor_id', 32)->unique();

            // Network and device. `ip` is nullable because it is only the
            // fallback identity when no cookie arrived, and can be absent behind
            // some proxies.
            $table->string('ip', 45)->nullable();
            $table->string('device', 20)->default('other');
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();

            // What they were looking at, and where they came from. Both are
            // truncated to column width by the service rather than trusted.
            $table->string('path', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('locale', 5)->default('fr');

            // Running total, so a frequent visitor is distinguishable from
            // someone who arrived once. `first_seen` is kept alongside
            // `last_seen` so the panel can show both without storing history.
            $table->unsignedInteger('visits_count')->default(1);
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');

            $table->timestamps();

            // The panel sorts by recency and filters by device, so these are the
            // two access paths worth indexing.
            $table->index('last_seen');
            $table->index('device');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
