<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Cart retention.
|
| Runs daily, on the assumption someone deploys on a schedule anyway and the
| scheduler is a cron entry away. The window matches the command's own default so
| a shop that has not thought about this still behaves as documented rather than
| accumulating carts forever.
|
| No `withoutOverlapping`: the command only deletes rows older than the cutoff,
| so a slow run overlapping the next one has nothing to conflict over, and the
| guard would only add a lock file to reason about. If the run is genuinely
| overlapping itself, the second one simply finds fewer rows.
*/
Schedule::command('carts:purge --days=30')->dailyAt('04:10');
