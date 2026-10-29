<?php

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Badge;
use App\Models\Notification;
use App\Models\QuestTemplate;
use App\Models\User;
use App\Models\UserQuest;
use App\Services\BadgeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every badge rule from PRD §4.6, unit tested. Badges grant no XP (the
 * PRD's badge table has no reward), unlock once (unique index) and always
 * notify.
 */
class BadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twelve_badges_exist(): void
    {
        $this->assertSame(12, Badge::query()->count());
    }

    public function test_first_blood_unlocks_on_the_first_event(): void
    {
        $user = User::factory()->create();

        $this->makeEvent($user, ActivityType::Commit);
        $unlocked = app(BadgeService::class)->check($user);

        $this->assertContains('first_blood', $unlocked);
        $this->assertSame(1, $user->badges()->count());

        $notification = Notification::query()->where('type', Notification::TYPE_BADGE_UNLOCKED)->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('First Blood', $notification->title);
    }

    public function test_a_second_check_does_not_unlock_twice(): void
    {
        $user = User::factory()->create();

        $this->makeEvent($user, ActivityType::Commit);
        app(BadgeService::class)->check($user);
        app(BadgeService::class)->check($user);

        $this->assertSame(1, $user->badges()->count());
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_BADGE_UNLOCKED)->count());
    }

    public function test_nothing_unlocks_without_activity(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], app(BadgeService::class)->check($user));
        $this->assertSame(0, $user->badges()->count());
    }

    public function test_week_warrior_and_monthly_monk_follow_the_longest_streak(): void
    {
        $user = User::factory()->create(['longest_streak' => 6]);
        $service = app(BadgeService::class);

        $service->check($user);
        $this->assertNotContains('week_warrior', $user->badges()->pluck('key')->all());

        $user->forceFill(['longest_streak' => 7])->save();
        $service->check($user->fresh());
        $this->assertContains('week_warrior', $user->badges()->pluck('key')->all());
        $this->assertNotContains('monthly_monk', $user->badges()->pluck('key')->all());

        $user->forceFill(['longest_streak' => 30])->save();
        $service->check($user->fresh());
        $this->assertContains('monthly_monk', $user->badges()->pluck('key')->all());
    }

    public function test_merge_master_needs_25_merges(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        foreach (range(1, 24) as $i) {
            $this->makeEvent($user, ActivityType::PrMerged, id: "m-{$i}");
        }
        $service->check($user);
        $this->assertNotContains('merge_master', $user->badges()->pluck('key')->all());

        $this->makeEvent($user, ActivityType::PrMerged, id: 'm-25');
        $service->check($user->fresh());
        $this->assertContains('merge_master', $user->badges()->pluck('key')->all());
    }

    public function test_code_reviewer_needs_20_reviews(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        foreach (range(1, 20) as $i) {
            $this->makeEvent($user, ActivityType::PrReview, id: "r-{$i}");
        }
        $service->check($user);

        $this->assertContains('code_reviewer', $user->badges()->pluck('key')->all());
    }

    public function test_night_owl_counts_commits_in_local_early_hours(): void
    {
        // UTC+5: 20:30 UTC = 01:30 local (inside the 00:00–04:00 night-owl window).
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $service = app(BadgeService::class);

        foreach (range(1, 10) as $i) {
            $this->makeEvent(
                $user,
                ActivityType::Commit,
                CarbonImmutable::parse('2026-10-25 20:30:00', 'UTC')->addMinutes($i),
                "n-{$i}",
            );
        }
        $service->check($user);

        $this->assertContains('night_owl', $user->badges()->pluck('key')->all());
    }

    public function test_early_bird_ignores_the_same_commits_when_local_time_is_wrong(): void
    {
        // 20:30 UTC = 01:30 local: night owl yes, early bird (04:00–07:00) no.
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $service = app(BadgeService::class);

        foreach (range(1, 10) as $i) {
            $this->makeEvent(
                $user,
                ActivityType::Commit,
                CarbonImmutable::parse('2026-10-25 20:30:00', 'UTC')->addMinutes($i),
                "e-{$i}",
            );
        }
        $service->check($user);

        $keys = $user->badges()->pluck('key')->all();
        $this->assertContains('night_owl', $keys);
        $this->assertNotContains('early_bird', $keys);
    }

    public function test_explorer_counts_ten_distinct_repositories(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        foreach (range(1, 10) as $i) {
            $this->makeEvent($user, ActivityType::Commit, now('UTC'), "x-{$i}", "acme/repo-{$i}");
        }
        // An 11th event in an existing repo must not change anything.
        $this->makeEvent($user, ActivityType::Commit, now('UTC'), 'x-11', 'acme/repo-1');

        $service->check($user);

        $this->assertContains('explorer', $user->badges()->pluck('key')->all());
    }

    public function test_level_10_follows_the_level(): void
    {
        // Level 4 (threshold for level 10 is 8100 XP).
        $user = User::factory()->withXp(900)->create();
        $service = app(BadgeService::class);

        $this->assertSame(4, $user->level);
        $service->check($user);
        $this->assertNotContains('level_10', $user->badges()->pluck('key')->all());

        $user->forceFill(['level' => 10])->save();
        $service->check($user->fresh());
        $this->assertContains('level_10', $user->badges()->pluck('key')->all());
    }

    public function test_quest_slayer_counts_25_completed_quests(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        $template = QuestTemplate::query()->where('key', 'daily_commit_1')->firstOrFail();

        foreach (range(1, 25) as $i) {
            UserQuest::query()->create([
                'user_id' => $user->id,
                'quest_template_id' => $template->id,
                'period_start' => now('UTC')->subDays($i)->toDateString(),
                'period_end' => now('UTC')->subDays($i)->toDateString(),
                'progress' => 1,
                'target' => 1,
                'status' => UserQuest::STATUS_COMPLETED,
                'completed_at' => now('UTC'),
            ]);
        }

        $service->check($user);
        $this->assertContains('quest_slayer', $user->badges()->pluck('key')->all());
    }

    public function test_boss_slayer_only_counts_weekly_quests(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        $daily = QuestTemplate::query()->where('key', 'daily_commit_1')->firstOrFail();
        $weekly = QuestTemplate::query()->where('period', 'weekly')->firstOrFail();

        // 25 completed daily quests must not trigger boss slayer.
        foreach (range(1, 5) as $i) {
            $this->completeQuest($user, $daily, now('UTC')->subDays($i)->toDateString());
        }
        foreach (range(6, 30) as $i) {
            $this->completeQuest($user, $daily, now('UTC')->subDays($i)->toDateString());
        }

        $service->check($user);
        $this->assertNotContains('boss_slayer', $user->badges()->pluck('key')->all());

        // 5 weekly boss completions do.
        foreach (range(1, 5) as $i) {
            $this->completeQuest($user, $weekly, now('UTC')->subWeeks($i)->toDateString());
        }

        $service->check($user->fresh());
        $this->assertContains('boss_slayer', $user->badges()->pluck('key')->all());
    }

    public function test_guild_founder_is_locked_until_a_guild_exists(): void
    {
        $user = User::factory()->create();

        app(BadgeService::class)->check($user);

        // Before M4's guilds table exists the rule is guarded and stays
        // locked; after it exists, no guild means still locked. The unlock
        // path is covered by the guild tests once M4 ships.
        $this->assertNotContains('guild_founder', $user->badges()->pluck('key')->all());
    }

    private function completeQuest(User $user, QuestTemplate $template, string $day): void
    {
        UserQuest::query()->create([
            'user_id' => $user->id,
            'quest_template_id' => $template->id,
            'period_start' => $day,
            'period_end' => $day,
            'progress' => $template->target,
            'target' => $template->target,
            'status' => UserQuest::STATUS_COMPLETED,
            'completed_at' => now('UTC'),
        ]);
    }

    private function makeEvent(User $user, ActivityType $type, $occurredAt = null, ?string $id = null, string $repo = 'acme/app'): ActivityEvent
    {
        return ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => $id ?? 'badge-'.uniqid('', true),
            'type' => $type,
            'repo_full_name' => $repo,
            'occurred_at' => $occurredAt ?? now('UTC'),
            'metadata' => ['message' => 'feat: something'],
        ]);
    }
}
