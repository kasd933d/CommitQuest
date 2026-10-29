<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Badge;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public endpoints from PRD §4.7/§4.8: profile, leaderboard, stats.
 */
class PublicTest extends TestCase
{
    use RefreshDatabase;

    // ---- Profile ----------------------------------------------------------

    public function test_a_public_profile_shows_everything_shareable(): void
    {
        $user = User::factory()->withXp(450)->withStreak(4, 9)->create([
            'username' => 'octo',
            'display_name' => 'Octo Cat',
        ]);

        $badge = Badge::query()->where('key', 'first_blood')->firstOrFail();
        $user->badges()->attach($badge->id, ['unlocked_at' => now('UTC')]);

        $json = $this->getJson('/api/v1/users/octo')->assertOk()->json();

        $this->assertSame('octo', $json['username']);
        $this->assertSame('Octo Cat', $json['display_name']);
        $this->assertSame(450, $json['total_xp']);
        $this->assertSame(3, $json['level']);
        $this->assertSame(4, $json['current_streak']);
        $this->assertSame(9, $json['longest_streak']);
        $this->assertCount(12 * 7, $json['heatmap']);
        $this->assertSame('first_blood', $json['badges'][0]['key']);
        $this->assertIsArray($json['recent_quests']);

        // The token must never ride along on a public endpoint.
        $this->assertStringNotContainsString($user->github_token, json_encode($json));
    }

    public function test_a_private_profile_is_a_404(): void
    {
        User::factory()->private()->create(['username' => 'ghost']);

        $this->getJson('/api/v1/users/ghost')->assertNotFound();
    }

    public function test_an_unknown_profile_is_a_404(): void
    {
        $this->getJson('/api/v1/users/nobody')->assertNotFound();
    }

    // ---- Leaderboard ------------------------------------------------------

    public function test_the_all_time_leaderboard_is_ranked_by_total_xp(): void
    {
        User::factory()->withXp(100)->create(['username' => 'bronze']);
        User::factory()->withXp(1000)->create(['username' => 'gold']);
        User::factory()->withXp(500)->create(['username' => 'silver']);
        User::factory()->withXp(9999)->private()->create(['username' => 'hidden']);

        $json = $this->getJson('/api/v1/leaderboard?scope=all')->assertOk()->json();

        $this->assertSame('all', $json['scope']);
        $this->assertSame(['gold', 'silver', 'bronze'], array_column($json['entries'], 'username'));
        $this->assertSame([1, 2, 3], array_column($json['entries'], 'rank'));
        $this->assertSame([1000, 500, 100], array_column($json['entries'], 'xp'));
    }

    public function test_the_weekly_leaderboard_uses_this_weeks_ledger_rows(): void
    {
        $thisWeek = User::factory()->create(['username' => 'fresh']);
        $old = User::factory()->create(['username' => 'veteran']);

        $this->giveXp($thisWeek, 300);
        $this->giveXp($old, 500, now('UTC')->subWeeks(3));

        $json = $this->getJson('/api/v1/leaderboard?scope=weekly')->assertOk()->json();

        $this->assertSame('weekly', $json['scope']);
        $this->assertNotNull($json['week_start']);
        $this->assertSame(['fresh'], array_column($json['entries'], 'username'));
    }

    public function test_an_invalid_leaderboard_scope_is_rejected(): void
    {
        $this->getJson('/api/v1/leaderboard?scope=galaxy')->assertStatus(422);
    }

    // ---- Stats ------------------------------------------------------------

    public function test_stats_aggregate_the_whole_platform(): void
    {
        $user = User::factory()->withXp(250)->create();

        ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => 's1',
            'type' => 'commit',
            'repo_full_name' => 'acme/app',
            'occurred_at' => now('UTC'),
        ]);
        ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => 's2',
            'type' => 'pr_merged',
            'repo_full_name' => 'acme/app',
            'occurred_at' => now('UTC'),
        ]);

        $json = $this->getJson('/api/v1/stats')->assertOk()->json();

        $this->assertSame(1, $json['users']);
        $this->assertSame(250, $json['total_xp']);
        $this->assertSame(1, $json['commits']);
        $this->assertSame(1, $json['pull_requests']);
        $this->assertSame(0, $json['quests_completed']);
        $this->assertSame(0, $json['badges_unlocked']);
        $this->assertSame(0, $json['guilds']);
    }

    public function test_public_endpoints_do_not_require_authentication(): void
    {
        User::factory()->create(['username' => 'open']);

        $this->getJson('/api/v1/users/open')->assertOk();
        $this->getJson('/api/v1/leaderboard')->assertOk();
        $this->getJson('/api/v1/stats')->assertOk();
    }

    private function giveXp(User $user, int $amount, $at = null): void
    {
        static $sequence = 0;

        XpLedger::query()->forceCreate([
            'user_id' => $user->id,
            'source_type' => XpLedger::SOURCE_ACTIVITY,
            'source_id' => ++$sequence,
            'amount' => $amount,
            'reason' => 'test',
            'created_at' => $at ?? now('UTC'),
            'updated_at' => $at ?? now('UTC'),
        ]);
    }
}
