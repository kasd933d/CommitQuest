<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Badge;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserQuest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Badges (PRD §4.6): permanent achievements evaluated after every ingestion.
 * Each rule is a cheap, deterministic check; unlocking inserts a
 * `(user_id, badge_id)` row (unique) and pushes a notification. Badges grant
 * no XP in v1 — the PRD's badge table has no reward column.
 */
class BadgeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Evaluate every badge the user has not unlocked yet.
     *
     * @return list<string> keys of the badges unlocked by this call
     */
    public function check(User $user): array
    {
        $unlocked = $user->badges()->pluck('badge_id')->all();
        $unlockedKeys = [];

        $badges = Badge::query()
            ->whereNotIn('id', $unlocked)
            ->get();

        foreach ($badges as $badge) {
            if (! $this->ruleMatches($user, $badge->key)) {
                continue;
            }

            $inserted = UserBadge::query()->insertOrIgnore([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'unlocked_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            if ($inserted === 0) {
                continue; // unlocked concurrently
            }

            $unlockedKeys[] = $badge->key;

            $this->notifications->push(
                $user,
                Notification::TYPE_BADGE_UNLOCKED,
                "Badge unlocked: {$badge->name}",
                $badge->description,
                ['key' => $badge->key, 'icon' => $badge->icon],
            );
        }

        return $unlockedKeys;
    }

    private function ruleMatches(User $user, string $key): bool
    {
        return match ($key) {
            'first_blood' => $this->hasEvents($user, 1),
            'week_warrior' => $user->longest_streak >= 7,
            'monthly_monk' => $user->longest_streak >= 30,
            'merge_master' => $this->countType($user, ActivityType::PrMerged) >= 25,
            'code_reviewer' => $this->countType($user, ActivityType::PrReview) >= 20,
            'night_owl' => $this->commitsInLocalHours($user, 0, 4) >= 10,
            'early_bird' => $this->commitsInLocalHours($user, 4, 7) >= 10,
            'explorer' => ActivityEvent::query()
                ->where('user_id', $user->id)
                ->distinct()
                ->count('repo_full_name') >= 10,
            'level_10' => $user->level >= 10,
            'quest_slayer' => UserQuest::query()
                ->where('user_id', $user->id)
                ->where('status', UserQuest::STATUS_COMPLETED)
                ->count() >= 25,
            'boss_slayer' => UserQuest::query()
                ->where('user_id', $user->id)
                ->where('status', UserQuest::STATUS_COMPLETED)
                ->whereHas('template', fn ($query) => $query->where('period', 'weekly'))
                ->count() >= 5,
            // Guild Founder (guilds ship in M4): matched lazily, re-checked
            // whenever the user's activity is processed. The table guard keeps
            // M3 working before M4's migration exists.
            'guild_founder' => Schema::hasTable('guilds')
                && (bool) \DB::table('guilds')->where('owner_id', $user->id)->exists(),
            default => false,
        };
    }

    private function hasEvents(User $user, int $needed): bool
    {
        return ActivityEvent::query()->where('user_id', $user->id)->count() >= $needed;
    }

    private function countType(User $user, ActivityType $type): int
    {
        return ActivityEvent::query()->where('user_id', $user->id)->where('type', $type)->count();
    }

    /**
     * Count commits whose local hour (user's timezone) falls in
     * [from, to). The commit count is checked first so the per-row timezone
     * conversion only happens once the badge is actually within reach.
     */
    private function commitsInLocalHours(User $user, int $from, int $to): int
    {
        $threshold = 10;

        $query = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->where('type', ActivityType::Commit);

        if ($query->count() < $threshold) {
            return 0;
        }

        $timezone = $user->timezone !== '' && \in_array($user->timezone, \timezone_identifiers_list(), true)
            ? $user->timezone
            : 'UTC';

        $count = 0;

        $query->pluck('occurred_at')->each(function (string $occurredAt) use (&$count, $timezone, $from, $to): void {
            $hour = CarbonImmutable::parse($occurredAt)->setTimezone($timezone)->hour;

            if ($hour >= $from && $hour < $to) {
                $count++;
            }
        });

        return $count;
    }
}
