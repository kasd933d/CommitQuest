<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * "Streak at risk" notifications (PRD §4.9): after 18:00 in the user's
 * timezone, anyone with an open streak and no activity yet that local day is
 * warned once. Dedupe is per local day via the notifications index.
 */
class NotifyStreakRisk extends Command
{
    /** Local hour from which the warning is sent (PRD §4.9: "after 6 PM"). */
    public const WARN_FROM_HOUR = 18;

    protected $signature = 'notify:streak-risk';

    protected $description = 'Warn users with an open streak and no activity yet today';

    public function handle(NotificationService $notifications): int
    {
        $warned = 0;

        User::query()
            ->where('current_streak', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($notifications, &$warned): void {
                foreach ($users as $user) {
                    if ($this->warnUser($user, $notifications)) {
                        $warned++;
                    }
                }
            });

        $this->info("Sent {$warned} streak warning(s).");

        return self::SUCCESS;
    }

    private function warnUser(User $user, NotificationService $notifications): bool
    {
        $timezone = \in_array($user->timezone, \timezone_identifiers_list(), true) ? $user->timezone : 'UTC';
        $now = CarbonImmutable::now($timezone);

        if ($now->hour < self::WARN_FROM_HOUR) {
            return false;
        }

        $localToday = $now->toDateString();

        if ($user->last_active_date?->format('Y-m-d') === $localToday) {
            return false; // already active today
        }

        $dayStart = $now->startOfDay()->setTimezone('UTC');

        $alreadyWarned = $user->notifications()
            ->where('type', Notification::TYPE_STREAK_AT_RISK)
            ->whereBetween('created_at', [$dayStart, now('UTC')])
            ->exists();

        if ($alreadyWarned) {
            return false;
        }

        // Only warn when today is genuinely at risk: no event on the local day.
        $eventsToday = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$dayStart, $now->setTimezone('UTC')])
            ->exists();

        if ($eventsToday) {
            return false;
        }

        $notifications->push(
            $user,
            Notification::TYPE_STREAK_AT_RISK,
            "🔥 Your {$user->current_streak}-day streak is at risk",
            'No activity yet today — commit something before midnight to keep the streak alive.',
            ['current_streak' => $user->current_streak, 'date' => $localToday],
        );

        return true;
    }
}
