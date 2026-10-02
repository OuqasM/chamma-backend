<?php

/**
 * Forget carts past their retention window.
 *
 * Run: php artisan carts:purge --days=30
 *
 * A cart row records what an unidentified person was about to buy. It is written
 * on every add and removed, and most of them never lead to an order — which is
 * the point of the feature, and also the reason it cannot be kept forever.
 * Retaining a record of everyone's near-misses indefinitely is not a reporting
 * need, it is a growing store of intentions that nobody consented to keep.
 *
 * Converted carts are kept by default. Once a cart has become an order it is no
 * longer a guess about someone's intentions, it is a record of a purchase, and
 * the `orders` table already holds the same person and the same lines. Passing
 * `--include-converted` prunes them too, which is the right call for a shop that
 * wants the carts table to hold only the current reporting window.
 */

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class PurgeCarts extends Command
{
    protected $signature = 'carts:purge
        {--days=30 : Delete unconverted carts untouched for this many days}
        {--include-converted : Also delete carts that became orders}
        {--dry-run : Report what would go without deleting it}';

    protected $description = 'Delete cart records past their retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = Cart::query()->where('updated_at', '<', $cutoff);

        if (! $this->option('include-converted')) {
            $query->whereNull('converted_at');
        }

        // Counted and summed before deleting, so the command can say what it did
        // without a second pass. `value` is the money those carts would have
        // been worth — the number an owner actually wants out of this report.
        $count = (clone $query)->count();
        $value = (float) (clone $query)->sum('subtotal');
        $items = (int) (clone $query)->sum('items_count');

        if ($count === 0) {
            $this->info('No carts older than '.$days.' '.$this->plural($days).' to remove.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d cart%s older than %d %s — %d line%s, %s',
            $dryRun ? 'Would remove' : 'Removing',
            $count,
            $count === 1 ? '' : 's',
            $days,
            $this->plural($days),
            $items,
            $items === 1 ? '' : 's',
            number_format($value, 2),
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        // `items` go with the carts. The relation is declared with
        // `cascadeOnDelete`, so this is the database doing it; the explicit
        // `delete()` is not needed and would be a second pass.
        $query->delete();

        $this->info('Done.');

        // The consequence worth stating out loud, because it silently changes a
        // number the panel shows. The summary's `total` and `conversion_rate` are
        // computed over the whole table, so once old failures are gone the rate
        // reads higher than it was — not because anyone converted, but because
        // the carts that did not are no longer there to be counted against.
        // The 7 and 30 day figures are unaffected, and are the ones to trust.
        if (! $this->option('include-converted')) {
            $this->warn(
                'Abandoned carts were dropped from the table, so the panel\'s all-time '
                .'conversion rate now counts only the retained window. Its 7 and 30 '
                .'day figures are unaffected.'
            );
        }

        return self::SUCCESS;
    }

    private function plural(int $days): string
    {
        return $days === 1 ? 'day' : 'days';
    }
}
