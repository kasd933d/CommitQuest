<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Services\BadgeService;
use App\Services\QuestService;
use App\Services\StreakService;
use App\Services\XpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one ingested event: XP first (caps may apply), then the game
 * systems that key off activity — streaks, quest progress and badges. Every
 * step is idempotent, so a job retry can never double-count.
 */
class ProcessActivityEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(
        XpService $xp,
        StreakService $streaks,
        QuestService $quests,
        BadgeService $badges,
    ): void {
        $event = ActivityEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $user = $event->user;

        if ($user === null) {
            return;
        }

        $xp->award($event);

        // A day counts when a qualifying event landed on it (PRD §4.4),
        // regardless of whether the anti-farming caps paid for it.
        $streaks->registerActivity($user, $event->occurred_at);

        $quests->progress($user);

        // Re-read: quest rewards may have levelled the user up.
        $badges->check($user->fresh());
    }
}
