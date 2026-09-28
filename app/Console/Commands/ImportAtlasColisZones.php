<?php

/**
 * Import the carrier's delivery zones and their shipping fees.
 *
 * Run: php artisan shipping:import-atlas
 *      php artisan shipping:import-atlas --dry-run
 *
 * The carrier publishes one tariff table; this command mirrors it into
 * config/shipping_zones.php, which ShippingZoneService reads for the checkout
 * city list, the live quote and the deliverable check. Re-run it whenever the
 * carrier changes a price or opens a new city — that is the whole point of
 * having the data in a file rather than typed by hand.
 *
 * It refuses to write when the page does not parse into a plausible tariff
 * table: a silent empty overwrite would close the store's delivery map.
 */

namespace App\Console\Commands;

use App\Support\Shipping\AtlasColisTariffTable;
use App\Support\Shipping\AtlasColisZoneFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Throwable;

class ImportAtlasColisZones extends Command
{
    protected $signature = 'shipping:import-atlas
        {--source= : Override the tariff URL (default: the carrier page)}
        {--dry-run : Fetch, compare and report, but leave the file alone}
        {--min-rows=100 : Refuse to write fewer rows than this}
        {--timeout=30 : HTTP timeout in seconds}';

    protected $description = 'Scrape the carrier tariff table into config/shipping_zones.php (cities + delivery fees)';

    public function handle(AtlasColisTariffTable $table): int
    {
        $source = (string) ($this->option('source') ?: config('shipping_zones.source', AtlasColisTariffTable::SOURCE));
        $minimum = max(1, (int) $this->option('min-rows'));

        $this->line("Source: <info>{$source}</info>");

        try {
            $html = $this->fetch($source, (int) $this->option('timeout'));
        } catch (Throwable $e) {
            $this->error("Fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $zones = $table->parse($html);

        if (count($zones) < $minimum) {
            $this->error(sprintf(
                'Parsed %d rows, expected at least %d — the tariff table probably moved or the site answered with something else. Nothing written.',
                count($zones),
                $minimum,
            ));

            return self::FAILURE;
        }

        $current = config('shipping_zones.zones', []);
        $diff = $table->diff($current, $zones);

        $fees = array_column($zones, 'fee');
        $withDelay = count(array_filter($zones, fn ($z) => $z['delay'] !== null));

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Rows parsed', count($zones)],
            ['Distinct cities', $diff['total']],
            ['Fee range', min($fees).' - '.max($fees).' MAD'],
            ['Rows with a delay', $withDelay],
            ['Added', $diff['added'] ? count($diff['added']) : '—'],
            ['Removed', $diff['removed'] ? count($diff['removed']) : '—'],
            ['Fee changed', $diff['changed'] ? count($diff['changed']) : '—'],
        ]);

        if ($diff['changed']) {
            $this->newLine();
            $this->line('<comment>Fee changes</comment>');

            foreach (array_slice($diff['changed'], 0, 20) as $change) {
                $this->line(sprintf('  %-28s %s → %s MAD', $change['city'], $change['from'], $change['to']));
            }

            if (count($diff['changed']) > 20) {
                $this->line('  … and '.(count($diff['changed']) - 20).' more');
            }
        }

        if ($diff['added']) {
            $this->newLine();
            $this->line('<info>New cities: '.implode(', ', array_slice($diff['added'], 0, 15)).(count($diff['added']) > 15 ? ' …' : ''));
        }

        if ($diff['removed']) {
            $this->newLine();
            $this->warn('No longer published (will stop being deliverable): '.implode(', ', array_slice($diff['removed'], 0, 15)).(count($diff['removed']) > 15 ? ' …' : ''));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Write '.count($zones).' rows to config/shipping_zones.php?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        try {
            $this->write($zones);
        } catch (Throwable $e) {
            $this->error("Import aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        // The old copy is still cached in the running process; drop it so this
        // process (and the next request) reads what was just written.
        Artisan::call('config:clear');

        $this->newLine();
        $this->info(sprintf('Wrote %d rows to config/%s', count($zones), AtlasColisZoneFile::PATH));
        $this->line('Verify: <info>php artisan tinker --execute="dump(count(app(App\Services\ShippingZoneService::class)->all()))"</info>');

        return self::SUCCESS;
    }

    private function fetch(string $url, int $timeout): string
    {
        $this->line('Fetching…');

        $response = Http::withHeaders([
            'User-Agent' => 'ChammaPerfumes/1.0 (+shipping tariff import)',
            'Accept-Language' => 'fr,en;q=0.8',
        ])
            ->timeout(max(5, $timeout))
            ->retry(2, 250)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} from {$url}");
        }

        return $response->body();
    }

    /**
     * Render beside the target, prove the result is loadable, then move it into
     * place: a failed write or a malformed file must never replace a working
     * tariff list.
     *
     * @param  array<int, array{code: string, city: string, fee: float, delay: ?string, refusal_fee: float, return_fee: float}>  $zones
     */
    private function write(array $zones): void
    {
        $path = config_path(AtlasColisZoneFile::PATH);
        $contents = AtlasColisZoneFile::render(
            $zones,
            (string) config('shipping_zones.source', AtlasColisTariffTable::SOURCE),
            now()->toDateString(),
        );

        $tmp = $path.'.'.getmypid().'.tmp';

        File::put($tmp, $contents);

        try {
            $rows = AtlasColisZoneFile::verify($tmp, count($zones));
        } catch (Throwable $e) {
            File::delete($tmp);

            throw $e;
        }

        File::move($tmp, $path);

        $this->line(sprintf('  verified %d rows load as a config array', $rows));
    }
}
