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

// M3: quest assignment follows each user's local midnight, so a frequent
// idempotent run replaces per-timezone crons (PRD §4.5).
Schedule::command('quests:assign-due')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notify:streak-risk')->everyFifteenMinutes()->withoutOverlapping();
