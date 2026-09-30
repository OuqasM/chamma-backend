<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the waiting list against a database that predates the `name` column.
 *
 * `name` was added by editing
 * 2026_09_30_140000_create_waitlist_entries_table.php in place, on the
 * reasoning that a migration which has only ever run inside the test suite
 * cannot have a deployed copy to patch. That reasoning was wrong: the phone-only
 * version of that migration shipped and was applied, so the `migrations` table
 * already recorded it, and editing the file changed nothing for any real
 * database. Deploying the code that inserts `name` on top of it made every
 * signup fail with "no column named name" — a 500 on a form a customer had
 * already filled in, which is the worst place for one.
 *
 * So the schema is reconciled here rather than by rewriting history. This
 * migration handles both states it can find, because there are two and the
 * symptom is identical:
 *
 *  - the table is absent, because no migration had ever been run;
 *  - the table exists in its original phone-only shape.
 *
 * A fresh install gets the table from the create migration and skips straight
 * past this; it is a no-op there, which is asserted in the tests.
 *
 * The added column is nullable rather than NOT NULL. Rows written before the
 * name existed have no name to give, and the alternatives are both worse:
 * a NOT NULL column cannot be added over them at all, and inventing a
 * placeholder would put words in a customer's record that nobody typed. New
 * rows are unaffected — `StoreWaitlistRequest` requires the name, and
 * `WaitlistService` always writes one — so only the pre-existing rows are ever
 * nameless, and the panel shows a dash for them.
 */
return new class extends Migration
{
    private const CREATE_MIGRATION = '2026_09_30_140000_create_waitlist_entries_table';

    public function up(): void
    {
        if (! Schema::hasTable('waitlist_entries')) {
            $this->createTable();

            return;
        }

        if (! Schema::hasColumn('waitlist_entries', 'name')) {
            Schema::table('waitlist_entries', function (Blueprint $table) {
                $table->string('name', 120)->nullable()->after('phone_normalised');
            });
        }
    }

    public function down(): void
    {
        // Whether the table predates this migration is exactly whether the
        // create migration was ever recorded as applied. Rolling back has to
        // reverse the path `up()` actually took: undoing a column addition on a
        // table this migration created would leave an orphan table that the
        // create migration also believes it owns.
        $tablePredatesUs = DB::table('migrations')
            ->where('migration', self::CREATE_MIGRATION)
            ->exists();

        if (! $tablePredatesUs) {
            Schema::dropIfExists('waitlist_entries');

            return;
        }

        if (Schema::hasColumn('waitlist_entries', 'name')) {
            Schema::table('waitlist_entries', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }

    /**
     * The create migration's schema, verbatim.
     *
     * Duplicated rather than reused so this file does not depend on the other
     * migration's internals staying stable, and so a future change to the
     * create cannot retroactively rewrite what an un-migrated database gets
     * here. If the two ever disagree, this one is the older shape and the
     * create migration will not run — the tests compare them.
     */
    private function createTable(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('phone', 30);
            $table->string('phone_normalised', 20);
            $table->string('name', 120)->nullable();

            $table->string('locale', 5)->default('fr');

            $table->timestamp('notified_at')->nullable();
            $table->string('notified_channel', 20)->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'phone_normalised']);
            $table->index(['product_id', 'notified_at']);
            $table->index('created_at');
        });
    }
};
