<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reproduces the deployed-schema bug: a database that ran the waitlist
 * migration BEFORE `name` was added to it.
 *
 * The `name` column was introduced by editing
 * 2026_09_30_140000_create_waitlist_entries_table.php in place, which only
 * works while that migration has never been applied anywhere persistent. It had
 * been: the phone-only version shipped and was run, so the migration table
 * already recorded it as applied and the amended version was skipped. The
 * application then inserted a column the database never received.
 *
 * Every signup from the storefront and the panel 500s, because `save()` is what
 * touches the missing column.
 */
class WaitlistLegacySchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Built through the admin API, the same way the rest of the waitlist suite
     * does, so the row is shaped exactly as production shapes it.
     */
    private function soldOutProduct(): Product
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
        ])->assertCreated();

        $product = Product::query()->latest('id')->firstOrFail();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        return $product->refresh();
    }

    /** Put the table back to the shape the phone-only migration produced. */
    private function revertToLegacySchema(): void
    {
        Schema::table('waitlist_entries', function ($table) {
            $table->dropColumn('name');
        });
    }

    /** Run the repair migration's up(), the way `php artisan migrate` would. */
    private function runRepair(): void
    {
        $migration = require database_path(
            'migrations/2026_09_30_170000_repair_waitlist_name_column.php'
        );

        $migration->up();
    }

    public function test_the_public_signup_survives_a_database_that_predates_the_name_column(): void
    {
        $product = $this->soldOutProduct();
        $this->revertToLegacySchema();

        $this->assertFalse(
            Schema::hasColumn('waitlist_entries', 'name'),
            'the fixture must really be missing the column, or this test proves nothing'
        );

        $this->runRepair();

        $this->assertTrue(
            Schema::hasColumn('waitlist_entries', 'name'),
            'the repair must restore the column a deployed database is missing'
        );

        $response = $this->postJson("/api/fr/products/{$product->slug}/waitlist", [
            'name' => 'Amina',
            'phone' => '0612345678',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('waitlist_entries', ['name' => 'Amina']);
    }

    public function test_the_repair_is_a_no_op_on_a_fresh_install(): void
    {
        // A database that already has the column must be left exactly alone,
        // or running this migration on a current environment would start
        // rewriting a table that is already correct.
        $before = Schema::getColumnListing('waitlist_entries');

        $this->runRepair();

        $this->assertSame($before, Schema::getColumnListing('waitlist_entries'));
    }

    public function test_the_repair_builds_the_table_when_it_is_missing_entirely(): void
    {
        // The other shape that produces the same 500: no migration has ever been
        // run, so the table is not there to be missing a column.
        Schema::drop('waitlist_entries');
        $this->assertFalse(Schema::hasTable('waitlist_entries'));

        $this->runRepair();

        $this->assertTrue(Schema::hasTable('waitlist_entries'));

        // Not just present: usable, with the merge rule the service relies on.
        $product = $this->soldOutProduct();

        $this->postJson("/api/fr/products/{$product->slug}/waitlist", [
            'name' => 'Amina',
            'phone' => '0612345678',
        ])->assertSuccessful();

        // A second signup for the same number must merge, not collide.
        $this->postJson("/api/fr/products/{$product->slug}/waitlist", [
            'name' => 'Amina B.',
            'phone' => '06 12 34 56 78',
        ])->assertOk();

        $this->assertSame(1, \DB::table('waitlist_entries')->count());
        $this->assertDatabaseHas('waitlist_entries', ['name' => 'Amina B.']);
    }

    public function test_a_row_that_predates_the_name_is_left_nameless_rather_than_invented(): void
    {
        // The column is nullable on purpose. Backfilling a placeholder would put
        // words in a customer's record that nobody typed.
        $this->revertToLegacySchema();

        \DB::table('waitlist_entries')->insert([
            'product_id' => $this->soldOutProduct()->id,
            'phone' => '0612345678',
            'phone_normalised' => '+212612345678',
            'locale' => 'fr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runRepair();

        $this->assertSame(
            1,
            \DB::table('waitlist_entries')->whereNull('name')->count(),
            'a legacy row must survive the repair without a fabricated name'
        );
    }
}
