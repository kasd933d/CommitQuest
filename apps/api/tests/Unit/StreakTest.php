<?php

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\StreakService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every streak rule from PRD §4.4, unit tested.
 */
class StreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_activity_starts_a_streak_of_one(): void
    {
        $user = User::factory()->create();
        $streaks = app(StreakService::class);

        $streaks->registerActivity($user, now('UTC'));

        $fresh = $user->fresh();
        $this->assertSame(1, $fresh->current_streak);
        $this->assertSame(1, $fresh->longest_streak);
        $this->assertNotNull($fresh->last_active_date);
    }

    public function test_the_same_local_day_only_counts_once(): void
    {
        $user = User::factory()->create();
        $streaks = app(StreakService::class);

        $streaks->registerActivity($user, now('UTC'));
        $streaks->registerActivity($user, now('UTC')->addHours(2));
        $streaks->registerActivity($user, now('UTC')->subHours(1));

        $this->assertSame(1, $user->fresh()->current_streak);
    }

    public function test_consecutive_local_days_grow_the_streak(): void
    {
        $user = User::factory()->create();
        $streaks = app(StreakService::class);

        for ($day = 0; $day < 5; $day++) {
            $streaks->registerActivity($user, now('UTC')->subDays(4 - $day));
        }

        $fresh = $user->fresh();
        $this->assertSame(5, $fresh->current_streak);
        $this->assertSame(5, $fresh->longest_streak);
    }

    public function test_a_missed_day_without_freezes_resets_the_streak_but_keeps_freezes(): void
    {
        $user = User::factory()->create(['current_streak' => 10, 'longest_streak' => 12, 'streak_freezes' => 1]);
        $streaks = app(StreakService::class);

        // Last active two days ago (one missed day) with no banked freeze.
        $user->forceFill([
            'last_active_date' => now('UTC')->subDays(2)->toDateString(),
            'streak_freezes' => 0,
        ])->save();

        $streaks->registerActivity($user, now('UTC'));

        $fresh = $user->fresh();
        $this->assertSame(1, $fresh->current_streak, 'streak restarts after an uncovered gap');
        $this->assertSame(12, $fresh->longest_streak, 'the longest streak is never regressed');
        $this->assertSame(0, $fresh->streak_freezes);
    }

    public function test_a_banked_freeze_covers_exactly_one_missed_day(): void
    {
        $user = User::factory()->create(['current_streak' => 6, 'longest_streak' => 6, 'streak_freezes' => 1]);
        $streaks = app(StreakService::class);

        // Yesterday active, today active: gap of one missed day? No — active
        // yesterday then today is a direct continuation. Use a 2-day gap:
        // last active two days ago means exactly one day was missed.
        $user->forceFill(['last_active_date' => now('UTC')->subDays(2)->toDateString()])->save();

        $streaks->registerActivity($user, now('UTC'));

        $fresh = $user->fresh();
        $this->assertSame(7, $fresh->current_streak, 'one freeze covers one missed day');
        // The consumed freeze is immediately replaced: reaching day 7 is a
        // 7-day milestone, which banks a new freeze (max 2).
        $this->assertSame(1, $fresh->streak_freezes);
    }

    public function test_freezes_do_not_cover_a_gap_larger_than_the_bank(): void
    {
        $user = User::factory()->create(['current_streak' => 10, 'longest_streak' => 10, 'streak_freezes' => 1]);
        $streaks = app(StreakService::class);

        // Last active 4 days ago → 3 missed days, only 1 freeze banked.
        $user->forceFill(['last_active_date' => now('UTC')->subDays(4)->toDateString()])->save();

        $streaks->registerActivity($user, now('UTC'));

        $fresh = $user->fresh();
        $this->assertSame(1, $fresh->current_streak);
        $this->assertSame(1, $fresh->streak_freezes, 'unusable freezes are kept for later');
    }

    public function test_a_freeze_is_banked_at_every_seven_day_milestone(): void
    {
        $user = User::factory()->create(['current_streak' => 6, 'longest_streak' => 6]);
        $streaks = app(StreakService::class);

        $user->forceFill(['last_active_date' => now('UTC')->subDay()->toDateString()])->save();
        $streaks->registerActivity($user, now('UTC'));

        $this->assertSame(1, $user->fresh()->streak_freezes, 'reaching a 7-day streak banks one freeze');
    }

    public function test_freezes_are_capped_at_two(): void
    {
        $user = User::factory()->create(['current_streak' => 13, 'longest_streak' => 13, 'streak_freezes' => 2]);
        $streaks = app(StreakService::class);

        $user->forceFill(['last_active_date' => now('UTC')->subDay()->toDateString()])->save();
        $streaks->registerActivity($user, now('UTC'));

        $this->assertSame(2, $user->fresh()->streak_freezes, 'never more than two banked freezes');
    }

    public function test_an_older_event_processed_late_never_regresses_the_streak(): void
    {
        $user = User::factory()->create(['current_streak' => 8, 'longest_streak' => 8]);
        $streaks = app(StreakService::class);

        // An event from 3 days ago arrives after today's activity.
        $user->forceFill(['last_active_date' => now('UTC')->toDateString()])->save();
        $streaks->registerActivity($user, now('UTC')->subDays(3));

        $fresh = $user->fresh();
        $this->assertSame(8, $fresh->current_streak);
        $this->assertSame(now('UTC')->toDateString(), $fresh->last_active_date->format('Y-m-d'));
    }

    public function test_the_local_day_is_decided_in_the_users_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']); // UTC+5
        $streaks = app(StreakService::class);

        // 20:00 UTC on the 25th is already 01:00 on the 26th in Karachi.
        $streaks->registerActivity($user, CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'));

        $this->assertSame('2026-10-26', $user->fresh()->last_active_date->format('Y-m-d'));
    }

    public function test_reprocessing_the_same_day_is_idempotent(): void
    {
        $user = User::factory()->create(['current_streak' => 3, 'longest_streak' => 3]);
        $streaks = app(StreakService::class);

        $user->forceFill(['last_active_date' => now('UTC')->toDateString()])->save();

        $streaks->registerActivity($user, now('UTC'));
        $streaks->registerActivity($user, now('UTC'));

        $this->assertSame(3, $user->fresh()->current_streak);
    }

    public function test_the_heatmap_returns_twelve_weeks_of_local_days(): void
    {
        $user = User::factory()->create();
        $streaks = app(StreakService::class);

        // Two events "today", one three days ago.
        $this->makeEvent($user, now('UTC'));
        $this->makeEvent($user, now('UTC')->addMinute());
        $this->makeEvent($user, now('UTC')->subDays(3));

        $days = $streaks->heatmap($user);

        $this->assertCount(12 * 7, $days);
        $this->assertSame(now('UTC')->toDateString(), $days[array_key_last($days)]['date'], 'today is the last cell');

        $byDate = array_column($days, 'count', 'date');
        $this->assertSame(2, $byDate[now('UTC')->toDateString()]);
        $this->assertSame(1, $byDate[now('UTC')->subDays(3)->toDateString()]);
        $this->assertSame(0, $byDate[now('UTC')->subDays(1)->toDateString()]);
    }

    public function test_the_heatmap_buckets_days_in_the_users_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $streaks = app(StreakService::class);

        // 20:00 UTC on the 25th = 01:00 on the 26th local time.
        $this->makeEvent($user, CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'));

        $byDate = array_column($streaks->heatmap($user), 'count', 'date');

        $this->assertSame(1, $byDate['2026-10-26'] ?? 0);
        $this->assertSame(0, $byDate['2026-10-25'] ?? 0);
    }

    private function makeEvent(User $user, $occurredAt): ActivityEvent
    {
        return ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => 'streak-'.uniqid('', true),
            'type' => ActivityType::Commit,
            'repo_full_name' => 'acme/app',
            'occurred_at' => $occurredAt,
            'metadata' => ['message' => 'feat: something'],
        ]);
    }
}
