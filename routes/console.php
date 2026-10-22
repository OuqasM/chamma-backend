<?php

use App\Models\PageView;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Store schedules
|--------------------------------------------------------------------------
*/

// The page view log is personal data (each row carries an IP address), so it
// is swept on a retention window rather than kept forever. The window itself
// is config('chamma.visits.retention_days'); this line only fires the sweep,
// which is scheduled to run once a day rather than be forgotten.
Schedule::command('model:prune', ['--model' => [PageView::class]])->daily();
