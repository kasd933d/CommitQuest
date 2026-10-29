<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Notification;
use App\Models\QuestTemplate;
use App\Models\User;
use App\Models\UserQuest;
use Carbon\CarbonImmutable;

/**
 * Quests (PRD §4.5):
 *  - every local day (user's timezone) three daily templates are assigned,
 *  - every local week (Monday start) one weekly "boss" template is assigned,
 *  - progress is recomputed from `activity_events` whenever activity is
 *    ingested, so it is idempotent and always consistent with the feed,
 *  - completing a quest awards XP (exempt from the daily activity caps),
 *    creates a notification and marks the quest completed — rewards are
 *    claimed automatically, there is no claim button.
 */
class QuestService
{
    /** How many daily quests are assigned per day (PRD §4.5). */
    public const DAILY_SLOTS = 3;

    public function __construct(
        private readonly XpService $xp,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Assign everything due for the user's current local day/week and expire
     * periods that ended. Safe to run as often as you like.
     *
     * @return list<UserQuest>
     */
    public function assignDue(User $user): array
    {
        $timezone = $this->timezoneOf($user->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $this->expireStale($user, $today);

        $created = [];

        // ---- Daily quests: 3 slots for the local day --------------------
        $assignedToday = UserQuest::query()
            ->where('user_id', $user->id)
            ->where('period_start', $today->toDateString())
            ->pluck('quest_template_id')
            ->all();

        $dailyTemplates = QuestTemplate::query()
            ->where('period', QuestTemplate::PERIOD_DAILY)
            ->where('is_active', true)
            ->get();

        $openSlots = self::DAILY_SLOTS - \count($assignedToday);

        if ($openSlots > 0) {
            $remaining = $dailyTemplates->reject(
                fn (QuestTemplate $template) => \in_array($template->id, $assignedToday, true)
            )->shuffle()->take($openSlots);

            foreach ($remaining as $template) {
                $quest = $this->assign($user, $template, $today, $today);
                if ($quest !== null) {
                    $created[] = $quest;
                }
            }
        }

        // ---- Weekly boss: one quest per local week ----------------------
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);

        $assignedThisWeek = UserQuest::query()
            ->where('user_id', $user->id)
            ->where('period_start', $weekStart->toDateString())
            ->whereHas('template', fn ($query) => $query->where('period', QuestTemplate::PERIOD_WEEKLY))
            ->exists();

        if (! $assignedThisWeek) {
            $weekly = QuestTemplate::query()
                ->where('period', QuestTemplate::PERIOD_WEEKLY)
                ->where('is_active', true)
                ->inRandomOrder()
                ->first();

            if ($weekly !== null) {
                $quest = $this->assign($user, $weekly, $weekStart, $weekStart->addDays(6));
                if ($quest !== null) {
                    $created[] = $quest;
                }
            }
        }

        return $created;
    }

    /**
     * Recompute progress for every active quest of the user and complete the
     * ones that reached their target. Idempotent: called after each ingested
     * event, and re-running it never double-pays thanks to the ledger's
     * unique index plus the status guard.
     *
     * @return list<UserQuest> the quests completed by this call
     */
    public function progress(User $user): array
    {
        $quests = UserQuest::query()
            ->with('template')
            ->where('user_id', $user->id)
            ->where('status', UserQuest::STATUS_ACTIVE)
            ->get();

        $completed = [];
        $now = CarbonImmutable::now($this->timezoneOf($user->timezone));

        foreach ($quests as $quest) {
            [$start, $end] = $this->window($user, $quest);
            $progress = $this->countMetric($user, $quest->template->metric, $start, $end);

            if ($progress >= $quest->target) {
                if ($this->complete($user, $quest, $progress)) {
                    $completed[] = $quest;
                }

                continue;
            }

            if ($now->greaterThanOrEqualTo($end)) {
                if ($quest->status !== UserQuest::STATUS_EXPIRED) {
                    $quest->forceFill(['progress' => $progress, 'status' => UserQuest::STATUS_EXPIRED])
                        ->save();
                }

                continue;
            }

            if ($quest->progress !== $progress) {
                $quest->forceFill(['progress' => $progress])->save();
            }
        }

        return $completed;
    }

    /**
     * Mark active quests whose period already ended as expired.
     */
    public function expireStale(User $user, CarbonImmutable $today): int
    {
        return UserQuest::query()
            ->where('user_id', $user->id)
            ->where('status', UserQuest::STATUS_ACTIVE)
            ->where('period_end', '<', $today->toDateString())
            ->update(['status' => UserQuest::STATUS_EXPIRED]);
    }

    private function assign(User $user, QuestTemplate $template, CarbonImmutable $start, CarbonImmutable $end): ?UserQuest
    {
        $inserted = UserQuest::query()->insertOrIgnore([
            'user_id' => $user->id,
            'quest_template_id' => $template->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'progress' => 0,
            'target' => $template->target,
            'status' => UserQuest::STATUS_ACTIVE,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        if ($inserted === 0) {
            return null; // already assigned (unique index)
        }

        return UserQuest::query()->where('quest_template_id', $template->id)
            ->where('user_id', $user->id)
            ->where('period_start', $start->toDateString())
            ->firstOrFail();
    }

    private function complete(User $user, UserQuest $quest, int $progress): bool
    {
        return (bool) \DB::transaction(function () use ($user, $quest, $progress): bool {
            $locked = UserQuest::query()->whereKey($quest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== UserQuest::STATUS_ACTIVE) {
                return false;
            }

            $locked->forceFill([
                'progress' => max($progress, $locked->progress),
                'status' => UserQuest::STATUS_COMPLETED,
                'completed_at' => now('UTC'),
            ])->save();

            $template = $locked->template;

            $this->xp->awardQuest($user, $locked, (int) $template->xp_reward, 'quest:'.$template->key);

            $this->notifications->push(
                $user,
                Notification::TYPE_QUEST_COMPLETE,
                "Quest complete: {$template->title}",
                "You earned {$template->xp_reward} XP.",
                [
                    'quest_id' => $locked->id,
                    'key' => $template->key,
                    'xp' => (int) $template->xp_reward,
                ],
            );

            return true;
        });
    }

    /**
     * Progress is measured over the quest period as a half-open UTC window
     * derived from the user's timezone (PRD §12: store UTC, convert
     * explicitly).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(User $user, UserQuest $quest): array
    {
        $timezone = $this->timezoneOf($user->timezone);

        // `period_start`/`period_end` are date-cast attributes (mutable
        // Carbon); re-wrap them as immutable before the timezone math.
        $start = CarbonImmutable::instance($quest->period_start)
            ->setTimezone($timezone)->startOfDay()->setTimezone('UTC');
        $end = CarbonImmutable::instance($quest->period_end)
            ->addDay()->setTimezone($timezone)->startOfDay()->setTimezone('UTC');

        return [$start, $end];
    }

    private function countMetric(User $user, string $metric, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $events = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$start, $end]);

        return match ($metric) {
            'commits' => (clone $events)->where('type', ActivityType::Commit)->count(),
            'pr_opened', 'pr_merged', 'pr_review', 'issue_closed' => (clone $events)->where('type', $metric)->count(),
            'distinct_repos' => (clone $events)->distinct()->count('repo_full_name'),
            'active_days' => $this->countActiveDays($user, $start, $end),
            default => 0,
        };
    }

    private function countActiveDays(User $user, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $timezone = $this->timezoneOf($user->timezone);
        $days = [];

        ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->pluck('occurred_at')
            ->each(function (string $occurredAt) use (&$days, $timezone): void {
                $days[CarbonImmutable::parse($occurredAt)->setTimezone($timezone)->toDateString()] = true;
            });

        return \count($days);
    }

    private function timezoneOf(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        return \in_array($timezone, \timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
