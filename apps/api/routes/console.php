<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---- CommitQuest scheduler (PRD §6.3) -------------------------------------
// Hourly XP/streak upkeep and background GitHub syncs. The `queue` container
// runs `php artisan queue:work`; this process only schedules.
Schedule::command('xp:rebuild')->hourlyAt(5)->withoutOverlapping();
Schedule::command('github:sync-active')->everyFifteenMinutes()->withoutOverlapping();
