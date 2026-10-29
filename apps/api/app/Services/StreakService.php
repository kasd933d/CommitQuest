<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Streaks (PRD §4.4). All day math happens in the user's timezone:
 * an event's UTC instant is converted to `user.timezone` and only then
 * compared with `last_active_date`.
 *
 * Rules:
 *  - a day counts when at least one qualifying event landed on that local day,
 *  - consecutive local days grow the streak (and the all-time longest),
 *  - every 7-day streak milestone banks one streak freeze (max 2),
 *  - banked freezes cover missed days 1:1; if the gap needs more freezes
 *    than the user owns, the streak restarts at 1.
 */
class StreakService
{
    /** Days shown on the dashboard heatmap (PRD §4.4: 12 weeks). */
    public const HEATMAP_WEEKS = 12;

    public const MAX_FREEZES = 2;

    public const FREEZE_PER_DAYS = 7;

    /**
     * Register activity for the local day the event happened on.
     * Repeat calls on the same local day are no-ops, so jobs can retry freely.
     */
    public function registerActivity(User $user, DateTimeInterface $at): void
    {
        $timezone = $this->timezoneOf($user->timezone);
        $localDate = CarbonImmutable::instance($at)->setTimezone($timezone);

        $today = $localDate->toDateString();
        $lastActive = $user->last_active_date?->format('Y-m-d');

        if ($lastActive === $today) {
            return;
        }

        // Out-of-order ingestion (an older event processed after a newer one)
        // must never move the streak backwards.
        if ($lastActive !== null && $lastActive > $today) {
            return;
        }

        $missedDays = $lastActive === null
            ? 0
            : (int) CarbonImmutable::parse($lastActive, $timezone)->diffInDays($localDate->startOfDay()) - 1;

        if ($missedDays <= 0) {
            // First event ever, or a direct continuation (gap of one day).
            $user->current_streak = $user->current_streak + 1;
        } elseif ($user->streak_freezes >= $missedDays) {
            // Each banked freeze covers exactly one missed day.
            $user->current_streak = $user->current_streak + 1;
            $user->streak_freezes = $user->streak_freezes - $missedDays;
        } else {
            $user->current_streak = 1;
        }

        if ($user->current_streak > $user->longest_streak) {
            $user->longest_streak = $user->current_streak;
        }

        // One freeze banked per completed 7-day milestone, capped at two.
        if ($user->current_streak > 0
            && $user->current_streak % self::FREEZE_PER_DAYS === 0
            && $user->streak_freezes < self::MAX_FREEZES) {
            $user->streak_freezes = $user->streak_freezes + 1;
        }

        $user->last_active_date = $today;
        $user->save();
    }

    /**
     * Last 12 weeks of local days with the number of qualifying events each
     * (PRD §4.4: dashboard heatmap). Oldest day first, today last.
     *
     * @return list<array{date: string, count: int}>
     */
    public function heatmap(User $user): array
    {
        $timezone = $this->timezoneOf($user->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $today->subDays(self::HEATMAP_WEEKS * 7 - 1);

        $windowStart = $start->setTimezone('UTC');
        $windowEnd = $today->addDay()->setTimezone('UTC');

        $counts = [];

        ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->pluck('occurred_at')
            ->each(function (string $occurredAt) use (&$counts, $timezone): void {
                $key = CarbonImmutable::parse($occurredAt)->setTimezone($timezone)->toDateString();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        $days = [];
        for ($day = $start; $day->lessThan($today->addDay()); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'count' => $counts[$key] ?? 0];
        }

        return $days;
    }

    private function timezoneOf(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        return \in_array($timezone, \timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
