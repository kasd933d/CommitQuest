<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\XpLedger;
use App\Support\Levels;
use Carbon\CarbonImmutable;

/**
 * Applies the XP rules from the PRD §4.3:
 *  - fixed XP per activity type (ActivityType::xp()),
 *  - max 10 commits per repo per day,
 *  - max 300 XP per day, evaluated on the day boundary of the user's timezone,
 *  - one ledger row per activity event, so awarding twice is impossible.
 *
 * Every mutation happens inside a transaction with the event row locked, and
 * `users.total_xp` / `users.level` are always recomputed from the ledger.
 */
class XpService
{
    /** Maximum commit activities credited per repository per user per day. */
    public const MAX_COMMITS_PER_REPO_PER_DAY = 10;

    /** Maximum XP a user can earn from activity per day (quest/badge XP is exempt). */
    public const MAX_XP_PER_DAY = 300;

    /**
     * Award XP for an event if the rules allow it.
     *
     * @return array{awarded: bool, amount: int, reason: string}
     */
    public function award(ActivityEvent $event): array
    {
        return \DB::transaction(function () use ($event) {
            $event = ActivityEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $event->isPending()) {
                return ['awarded' => false, 'amount' => 0, 'reason' => 'already_handled'];
            }

            $user = $event->user;

            if ($user === null) {
                $event->markSkipped('missing_user');

                return ['awarded' => false, 'amount' => 0, 'reason' => 'missing_user'];
            }

            [$dayStart, $dayEnd] = $this->dayWindow($event->occurred_at, $this->timezoneOf($user->timezone));

            // Anti-farming: max 10 commits per repo per day.
            if ($event->type === ActivityType::Commit) {
                $creditedToday = ActivityEvent::query()
                    ->where('user_id', $user->id)
                    ->where('type', ActivityType::Commit)
                    ->where('repo_full_name', $event->repo_full_name)
                    ->whereNotNull('awarded_at')
                    ->whereBetween('occurred_at', [$dayStart, $dayEnd])
                    ->count();

                if ($creditedToday >= self::MAX_COMMITS_PER_REPO_PER_DAY) {
                    $event->markSkipped('daily_repo_cap');

                    return ['awarded' => false, 'amount' => 0, 'reason' => 'daily_repo_cap'];
                }
            }

            $amount = $event->type->xp();

            // Anti-farming: max 300 XP from activity per day. Quest and badge
            // rewards live in the same ledger but are exempt, so the cap is
            // measured on the activity events themselves (by the day the
            // activity happened, not the day it was synced).
            $todayXp = (int) ActivityEvent::query()
                ->where('user_id', $user->id)
                ->whereNotNull('awarded_at')
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])
                ->sum('xp_awarded');

            if ($todayXp + $amount > self::MAX_XP_PER_DAY) {
                $event->markSkipped('daily_xp_cap');

                return ['awarded' => false, 'amount' => 0, 'reason' => 'daily_xp_cap'];
            }

            // Unique (user_id, source_type, source_id) makes a double award impossible.
            XpLedger::query()->create([
                'user_id' => $user->id,
                'source_type' => XpLedger::SOURCE_ACTIVITY,
                'source_id' => $event->id,
                'amount' => $amount,
                'reason' => $this->reason($event),
            ]);

            $event->markAwarded($amount);

            $this->refreshTotals($user->id);

            return ['awarded' => true, 'amount' => $amount, 'reason' => 'awarded'];
        });
    }

    /**
     * Recompute total_xp and level from the ledger (PRD §4.3: the cached sum
     * can always be rebuilt identically).
     *
     * @return array{total_xp: int, level: int}
     */
    public function recomputeFor(int $userId): array
    {
        $total = (int) XpLedger::query()->where('user_id', $userId)->sum('amount');
        $level = Levels::fromXp($total);

        \DB::table('users')->where('id', $userId)->update([
            'total_xp' => $total,
            'level' => $level,
        ]);

        return ['total_xp' => $total, 'level' => $level];
    }

    /**
     * @return array{total_xp: int, level: int}
     */
    private function refreshTotals(int $userId): array
    {
        return $this->recomputeFor($userId);
    }

    private function reason(ActivityEvent $event): string
    {
        $suffix = $event->repo_full_name !== '' ? ' '.$event->repo_full_name : '';

        return \mb_substr('activity:'.$event->type->value.$suffix, 0, 191);
    }

    /**
     * Half-open [start, end) day window in UTC for a moment, using the user's
     * timezone for the day boundary (PRD §10: store UTC, convert explicitly).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayWindow(\DateTimeInterface $at, string $timezone): array
    {
        $start = CarbonImmutable::instance($at)
            ->setTimezone($timezone)
            ->startOfDay()
            ->setTimezone('UTC');

        return [$start, $start->addDay()];
    }

    private function timezoneOf(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        return \in_array($timezone, \timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
