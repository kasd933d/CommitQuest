<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGithubEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $userId) {}

    public function handle(SyncService $sync): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || $user->github_token === null) {
            return;
        }

        $sync->syncUser($user);
    }
}
