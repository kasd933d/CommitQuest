<?php

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Jobs\ProcessActivityEvent;
use App\Models\ActivityEvent;
use App\Models\Notification;
use App\Models\QuestTemplate;
use App\Models\User;
use App\Models\UserQuest;
use App\Models\XpLedger;
use App\Services\QuestService;
use App\Services\XpService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quest rules from PRD §4.5: assignment, automatic progress, completion
 * (XP + notification in one transaction) and idempotency.
 */
class QuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_daily_quests_and_one_weekly_boss_are_assigned(): void
    {
        $user = User::factory()->create();
        $quests = app(QuestService::class);

        $created = $quests->assignDue($user);

        $dailies = $user->quests()->whereHas('template', fn ($q) => $q->where('period', 'daily'))->get();
        $weeklies = $user->quests()->whereHas('template', fn ($q) => $q->where('period', 'weekly'))->get();

        $this->assertCount(3, $dailies, 'PRD §4.5: three daily quests');
        $this->assertCount(1, $weeklies, 'PRD §4.5: one weekly boss');
        $this->assertCount(4, $created);

        foreach ($dailies as $quest) {
            $this->assertSame($quest->period_start->toDateString(), $quest->period_end->toDateString());
        }

        $boss = $weeklies->first();
        $this->assertSame(CarbonImmutable::now($user->timezone)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $boss->period_start->toDateString());
    }

    public function test_assignment_is_idempotent(): void
    {
        $user = User::factory()->create();
        $quests = app(QuestService::class);

        $first = $quests->assignDue($user);
        $second = $quests->assignDue($user);
        $third = $quests->assignDue($user);

        $this->assertCount(4, $first);
        $this->assertCount(0, $second);
        $this->assertCount(0, $third);
        $this->assertSame(4, $user->quests()->count());
    }

    public function test_quest_progress_counts_events_inside_the_quest_window(): void
    {
        $user = User::factory()->create();
        $template = QuestTemplate::query()->where('key', 'daily_commit_3')->firstOrFail();

        $quest = $this->assign($user, $template);

        // Two commits today (inside the window).
        $this->makeEvent($user, ActivityType::Commit, now('UTC'));
        $this->makeEvent($user, ActivityType::Commit, now('UTC'));

        app(QuestService::class)->progress($user);

        $this->assertSame(2, $quest->fresh()->progress);
        $this->assertSame(UserQuest::STATUS_ACTIVE, $quest->fresh()->status);
    }

    public function test_completing_a_quest_awards_xp_and_creates_a_notification(): void
    {
        $user = User::factory()->create();
        $template = QuestTemplate::query()->where('key', 'daily_commit_3')->firstOrFail();
        $quest = $this->assign($user, $template);

        foreach (range(1, 3) as $i) {
            $event = $this->makeEvent($user, ActivityType::Commit, now('UTC')->addMinutes($i));
            $this->app->call([new ProcessActivityEvent($event->id), 'handle']);
        }

        $quest->refresh();
        $this->assertSame(UserQuest::STATUS_COMPLETED, $quest->status);
        $this->assertNotNull($quest->completed_at);
        $this->assertSame(3, $quest->progress);

        // XP landed in the ledger (auto-claimed, no claim button).
        $ledger = XpLedger::query()
            ->where('user_id', $user->id)
            ->where('source_type', XpLedger::SOURCE_QUEST)
            ->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(30, $ledger->first()->amount);
        $this->assertSame('quest:daily_commit_3', $ledger->first()->reason);
        $this->assertSame(30 + 15, $user->fresh()->total_xp, '3 commit XP + 30 quest XP');

        // Notification exists (PRD §4.5).
        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', Notification::TYPE_QUEST_COMPLETE)
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString($template->title, $notification->title);
    }

    public function test_a_completed_quest_is_never_paid_twice(): void
    {
        $user = User::factory()->create();
        $template = QuestTemplate::query()->where('key', 'daily_commit_1')->firstOrFail();
        $quest = $this->assign($user, $template);

        $event = $this->makeEvent($user, ActivityType::Commit, now('UTC'));
        $job = new ProcessActivityEvent($event->id);

        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);
        app(QuestService::class)->progress($user);

        $this->assertSame(UserQuest::STATUS_COMPLETED, $quest->fresh()->status);
        $this->assertSame(1, XpLedger::query()->where('source_type', XpLedger::SOURCE_QUEST)->count());
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_QUEST_COMPLETE)->count());
    }

    public function test_quest_xp_is_exempt_from_the_daily_activity_cap(): void
    {
        $user = User::factory()->create();
        $xp = app(XpService::class);

        // Fill the activity cap for the day exactly: 10 commits in repo A
        // (50) + 10 commits in repo B (50) + 5 merges (200) = 300 XP.
        for ($i = 0; $i < 10; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, now('UTC'), "c-{$i}", 'acme/a'));
        }
        for ($i = 0; $i < 10; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, now('UTC'), "d-{$i}", 'acme/b'));
        }
        for ($i = 0; $i < 5; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::PrMerged, now('UTC'), "m-{$i}"));
        }

        $this->assertSame(300, $user->fresh()->total_xp);

        // A quest completed after the cap still pays.
        $template = QuestTemplate::query()->where('key', 'daily_commit_3')->firstOrFail();
        $quest = $this->assign($user, $template);
        $quest->forceFill(['progress' => 3, 'target' => 3])->save();

        app(QuestService::class)->progress($user);

        $this->assertSame(330, $user->fresh()->total_xp, 'quest XP bypasses the activity cap');
        $this->assertSame(UserQuest::STATUS_COMPLETED, $quest->fresh()->status);
    }

    public function test_progress_is_measured_over_the_quest_window_not_all_time(): void
    {
        $user = User::factory()->create();
        $template = QuestTemplate::query()->where('key', 'daily_commit_3')->firstOrFail();
        $quest = $this->assign($user, $template);

        // Two commits yesterday (before the window) and one today.
        $this->makeEvent($user, ActivityType::Commit, now('UTC')->subDay());
        $this->makeEvent($user, ActivityType::Commit, now('UTC')->subDay());
        $this->makeEvent($user, ActivityType::Commit, now('UTC'));

        app(QuestService::class)->progress($user);

        $this->assertSame(1, $quest->fresh()->progress, 'only events inside the window count');
    }

    public function test_a_quest_whose_period_ended_expires_without_paying(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $template = QuestTemplate::query()->where('key', 'daily_commit_3')->firstOrFail();

        // Yesterday's quest, still active because nobody ran the scheduler.
        $quest = UserQuest::query()->create([
            'user_id' => $user->id,
            'quest_template_id' => $template->id,
            'period_start' => now('UTC')->subDays(2)->toDateString(),
            'period_end' => now('UTC')->subDay()->toDateString(),
            'progress' => 2,
            'target' => 3,
            'status' => UserQuest::STATUS_ACTIVE,
        ]);

        app(QuestService::class)->progress($user);

        $this->assertSame(UserQuest::STATUS_EXPIRED, $quest->fresh()->status);
        $this->assertSame(0, XpLedger::query()->where('source_type', XpLedger::SOURCE_QUEST)->count());
    }

    public function test_daily_assignment_respects_the_users_local_day(): void
    {
        $user = User::factory()->create(['timezone' => 'Pacific/Auckland']); // ahead of UTC
        $quests = app(QuestService::class);

        $quests->assignDue($user);

        $today = CarbonImmutable::now($user->timezone)->toDateString();

        $this->assertSame(
            $today,
            $user->quests()->where('period_start', $today)->count() > 0
                ? $today
                : 'wrong local day'
        );
        $this->assertSame(3, $user->quests()->where('period_start', $today)->whereHas('template', fn ($q) => $q->where('period', 'daily'))->count());
    }

    public function test_distinct_repos_quest_counts_unique_repositories(): void
    {
        $user = User::factory()->create();
        $template = QuestTemplate::query()->where('key', 'daily_two_repos')->firstOrFail();
        $quest = $this->assign($user, $template);

        $this->makeEvent($user, ActivityType::Commit, now('UTC'), 'evt-1', 'acme/one');
        $this->makeEvent($user, ActivityType::Commit, now('UTC'), 'evt-2', 'acme/one');
        $this->makeEvent($user, ActivityType::Commit, now('UTC'), 'evt-3', 'acme/two');

        app(QuestService::class)->progress($user);

        $this->assertSame(2, $quest->fresh()->progress, 'two distinct repos, three events');
    }

    private function assign(User $user, QuestTemplate $template): UserQuest
    {
        $today = CarbonImmutable::now($user->timezone)->startOfDay();

        return UserQuest::query()->create([
            'user_id' => $user->id,
            'quest_template_id' => $template->id,
            'period_start' => $today->toDateString(),
            'period_end' => $today->toDateString(),
            'progress' => 0,
            'target' => $template->target,
            'status' => UserQuest::STATUS_ACTIVE,
        ]);
    }

    private function makeEvent(User $user, ActivityType $type, $occurredAt, ?string $id = null, string $repo = 'acme/app'): ActivityEvent
    {
        return ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => $id ?? 'quest-'.uniqid('', true),
            'type' => $type,
            'repo_full_name' => $repo,
            'occurred_at' => $occurredAt,
            'metadata' => ['message' => 'feat: something'],
        ]);
    }
}
