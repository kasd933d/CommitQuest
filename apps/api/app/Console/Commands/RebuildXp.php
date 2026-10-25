<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\XpService;
use Illuminate\Console\Command;

class RebuildXp extends Command
{
    protected $signature = 'xp:rebuild {--user= : Rebuild a single user id}';

    protected $description = 'Recompute total_xp and level for all users from the XP ledger';

    public function handle(XpService $xp): int
    {
        $query = User::query();

        if ($this->option('user') !== null) {
            $query->where('id', (int) $this->option('user'));
        }

        $count = 0;

        foreach ($query->pluck('id') as $id) {
            $stats = $xp->recomputeFor((int) $id);
            $this->line("user {$id}: {$stats['total_xp']} xp, level {$stats['level']}");
            $count++;
        }

        $this->info("Rebuilt XP for {$count} user(s).");

        return self::SUCCESS;
    }
}
