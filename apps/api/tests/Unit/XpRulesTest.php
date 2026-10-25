<?php

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Jobs\ProcessActivityEvent;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\XpService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every XP rule and cap from PRD §4.3, unit tested.
 */
class XpRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_xp_per_activity_type_matches_the_prd_table(): void
    {
        $expected = [
            ActivityType::Commit->value => 5,
            ActivityType::PrOpened->value => 20,
            ActivityType::PrMerged->value => 40,
            ActivityType::PrReview->value => 25,
            ActivityType::IssueClosed->value => 15,
            ActivityType::NewRepo->value => 30,
        ];

        $user = User::factory()->create();
        $xp = app(XpService::class);

        foreach ($expected as $type => $amount) {
            $event = $this->makeEvent($user, ActivityType::from($type));

            $result = $xp->award($event);

            $this->assertTrue($result['awarded'], "type {$type} should be awarded");
            $this->assertSame($amount, $result['amount'], "type {$type} XP mismatch");
        }

        $this->assertSame(array_sum($expected), (int) XpLedger::query()->where('user_id', $user->id)->sum('amount'));
        $this->assertSame(array_sum($expected), $user->fresh()->total_xp);
    }

    public function test_awarding_the_same_event_twice_only_pays_once(): void
    {
        $user = User::factory()->create();
        $event = $this->makeEvent($user, ActivityType::Commit);
        $xp = app(XpService::class);

        $first = $xp->award($event);
        $second = $xp->award($event->fresh());

        $this->assertTrue($first['awarded']);
        $this->assertFalse($second['awarded']);
        $this->assertSame('already_handled', $second['reason']);
        $this->assertSame(1, XpLedger::query()->where('user_id', $user->id)->count());
        $this->assertSame(5, $user->fresh()->total_xp);
    }

    public function test_the_job_wrapper_is_idempotent_too(): void
    {
        $user = User::factory()->create();
        $event = $this->makeEvent($user, ActivityType::PrMerged);

        (new ProcessActivityEvent($event->id))->handle(app(XpService::class));
        (new ProcessActivityEvent($event->id))->handle(app(XpService::class));

        $this->assertSame(40, $user->fresh()->total_xp);
        $this->assertSame(1, XpLedger::query()->count());
    }

    public function test_only_the_first_ten_commits_per_repo_per_day_are_paid(): void
    {
        $user = User::factory()->create();
        $xp = app(XpService::class);

        for ($i = 0; $i < 12; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, [
                'github_event_id' => "push-{$i}",
                'occurred_at' => now('UTC')->subHours(2)->addMinutes($i),
            ]));
        }

        $awarded = ActivityEvent::query()->where('user_id', $user->id)->whereNotNull('awarded_at')->count();
        $skipped = ActivityEvent::query()->where('user_id', $user->id)->where('skipped_reason', 'daily_repo_cap')->count();

        $this->assertSame(XpService::MAX_COMMITS_PER_REPO_PER_DAY, $awarded);
        $this->assertSame(2, $skipped);
        $this->assertSame(50, $user->fresh()->total_xp);
    }

    public function test_the_repo_cap_is_per_repository(): void
    {
        $user = User::factory()->create();
        $xp = app(XpService::class);

        for ($i = 0; $i < 10; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, ['github_event_id' => "a-{$i}"]));
        }

        $other = $xp->award($this->makeEvent($user, ActivityType::Commit, [
            'github_event_id' => 'other-repo',
            'repo_full_name' => 'acme/other',
        ]));

        $this->assertTrue($other['awarded']);
        $this->assertSame(55, $user->fresh()->total_xp);
    }

    public function test_activity_xp_is_capped_at_300_per_day(): void
    {
        $user = User::factory()->create();
        $xp = app(XpService::class);

        // 7 merged PRs = 280 XP. The 8th would take it to 320 > 300.
        for ($i = 0; $i < 8; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::PrMerged, ['github_event_id' => "pr-{$i}"]));
        }

        $awardedXp = (int) ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereNotNull('awarded_at')
            ->sum('xp_awarded');

        $this->assertSame(280, $awardedXp);
        $this->assertSame(280, $user->fresh()->total_xp);
        $this->assertSame(1, ActivityEvent::query()->where('skipped_reason', 'daily_xp_cap')->count());
    }

    public function test_the_daily_caps_use_the_users_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']); // UTC+5
        $xp = app(XpService::class);

        // 20:00 UTC on the 25th is already 01:00 on the 26th in Karachi, so
        // these ten commits fill the cap for the local day of October 26th.
        for ($i = 0; $i < 10; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, [
                'github_event_id' => "night-{$i}",
                'occurred_at' => CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC')->addMinutes($i),
            ]));
        }

        $overTheLine = $xp->award($this->makeEvent($user, ActivityType::Commit, [
            'github_event_id' => 'late-same-local-day',
            'occurred_at' => CarbonImmutable::parse('2026-10-26 10:00:00', 'UTC'), // 15:00 local, still Oct 26
        ]));

        $previousLocalDay = $xp->award($this->makeEvent($user, ActivityType::Commit, [
            'github_event_id' => 'previous-local-day',
            'occurred_at' => CarbonImmutable::parse('2026-10-25 10:00:00', 'UTC'), // 15:00 local, Oct 25
        ]));

        $this->assertFalse($overTheLine['awarded']);
        $this->assertSame('daily_repo_cap', $overTheLine['reason']);
        $this->assertTrue($previousLocalDay['awarded'], 'a commit on the previous local day still fits the cap');
    }

    public function test_total_xp_and_level_are_rebuilt_identically_from_the_ledger(): void
    {
        $user = User::factory()->withXp(9999)->create();
        $xp = app(XpService::class);

        $xp->award($this->makeEvent($user, ActivityType::PrMerged));
        $xp->award($this->makeEvent($user, ActivityType::Commit));

        $expected = (int) XpLedger::query()->where('user_id', $user->id)->sum('amount');

        // Corrupt the cached counters, then rebuild.
        $user->forceFill(['total_xp' => 12345, 'level' => 99])->save();

        $this->artisan('xp:rebuild', ['--user' => $user->id])->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertSame($expected, $fresh->total_xp);
        $this->assertSame((int) floor(sqrt($expected / 100)) + 1, $fresh->level);
    }

    public function test_skipped_events_never_create_ledger_rows(): void
    {
        $user = User::factory()->create();
        $xp = app(XpService::class);

        for ($i = 0; $i < 11; $i++) {
            $xp->award($this->makeEvent($user, ActivityType::Commit, [
                'github_event_id' => "capped-{$i}",
                'occurred_at' => now('UTC')->subHour(),
            ]));
        }

        $this->assertSame(10, XpLedger::query()->where('user_id', $user->id)->count());
        $this->assertSame(50, $user->fresh()->total_xp);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(User $user, ActivityType $type, array $overrides = []): ActivityEvent
    {
        return ActivityEvent::query()->create(array_merge([
            'user_id' => $user->id,
            'github_event_id' => 'evt-'.uniqid('', true),
            'type' => $type,
            'repo_full_name' => 'acme/app',
            'occurred_at' => now('UTC'),
            'metadata' => ['message' => 'feat: something'],
        ], $overrides));
    }
}
