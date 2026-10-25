<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncGithubEvents;
use App\Models\User;
use Illuminate\Console\Command;

class DispatchSyncsForActiveUsers extends Command
{
    protected $signature = 'github:sync-active {--hours=24 : Only users active within this many hours}';

    protected $description = 'Queue a GitHub events sync for users who were active recently';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $userIds = User::query()
            ->whereNotNull('github_token')
            ->where('updated_at', '>=', now('UTC')->subHours($hours))
            ->pluck('id');

        foreach ($userIds as $userId) {
            SyncGithubEvents::dispatch((int) $userId);
        }

        $this->info("Dispatched sync for {$userIds->count()} user(s).");

        return self::SUCCESS;
    }
}
