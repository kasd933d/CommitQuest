<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\QuestService;
use Illuminate\Console\Command;

/**
 * Assigns the three daily quests and the weekly boss quest for every user's
 * current local day/week (PRD §4.5: "each day at 00:00 in the user's
 * timezone"). The scheduler fires this every 15 minutes, which keeps every
 * timezone's midnight covered without per-user cron scheduling; assignment is
 * idempotent (unique index), so extra runs are harmless.
 */
class AssignDueQuests extends Command
{
    protected $signature = 'quests:assign-due {--hours=168 : Only users active within this many hours}';

    protected $description = 'Assign due daily and weekly quests for every active user';

    public function handle(QuestService $quests): int
    {
        $hours = (int) $this->option('hours');

        $users = User::query()
            ->where('updated_at', '>=', now('UTC')->subHours($hours))
            ->get();

        $assigned = 0;

        foreach ($users as $user) {
            $assigned += \count($quests->assignDue($user));
        }

        $this->info("Assigned {$assigned} quest(s) for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
