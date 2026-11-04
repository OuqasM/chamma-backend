<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts every phone the shop holds into the national form.
 *
 * The number is written "0612345678": ten characters, the leading zero, no
 * country code. It is how a Moroccan number is written, how the owner reads it
 * off a card, and how it fits in a column without being cut short.
 *
 * The rows written before this migration carry the country code — "+212…" —
 * because that was the canonical form until now. They are rewritten here rather
 * than left mixed, for two reasons:
 *
 * - **the panel showed the two shapes side by side**, which is exactly the
 *   "is this the same number?" question the canonical form exists to answer;
 * - **the unique indexes are on the reduced column**, so a number that had
 *   been stored one way and is looked up another would have stopped matching
 *   itself — the waiting list would have grown a second entry for a customer
 *   who signed up twice, and a customer's orders would have come apart.
 *
 * The rewrite is one-to-one: every old form reduces to exactly one new form,
 * so no two rows can collide and no unique index is at risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('orders', ['phone', 'phone_normalised']);
        $this->rewrite('customers', ['phone', 'phone_normalised']);
        $this->rewrite('waitlist_entries', ['phone', 'phone_normalised']);
    }

    public function down(): void
    {
        // The country code cannot be put back on the numbers that never had
        // one, and a number that goes back to a shape nobody typed is no
        // better than the one it came from. Left alone.
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function rewrite(string $table, array $columns): void
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return;
        }

        $hasPhone = in_array('phone', $columns, true);

        DB::table($table)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($table, $columns, $hasPhone) {
                foreach ($rows as $row) {
                    $source = $hasPhone ? (string) $row->phone : (string) ($row->phone_normalised ?? '');

                    $normalised = PhoneNumber::normalise($source);

                    if ($normalised === null) {
                        continue;
                    }

                    $update = [];

                    foreach ($columns as $column) {
                        if ((string) ($row->{$column} ?? '') !== $normalised) {
                            $update[$column] = $normalised;
                        }
                    }

                    if ($update === []) {
                        continue;
                    }

                    // Straight to the table, so `updated_at` keeps saying when
                    // the row itself last changed.
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            });
    }
};
