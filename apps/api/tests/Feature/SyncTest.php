<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sync_ingests_events_and_awards_xp(): void
    {
        Http::fake(['*' => Http::response($this->githubEvents(), 200, [
            'ETag' => '"v1"',
            'X-RateLimit-Remaining' => '4500',
        ])]);

        $user = User::factory()->create();

        $this->withAuth($user)
            ->postJson('/api/v1/me/sync')
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        // One commit + one pr_opened + the new_repo bonus.
        $this->assertSame(3, ActivityEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame(5 + 20 + 30, $user->fresh()->total_xp);

        $run = SyncRun::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('ok', $run->status);
        $this->assertSame(2, $run->events_fetched);
        $this->assertSame(3, $run->events_new);
        $this->assertSame(4500, $run->rate_limit_remaining);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->last_synced_at);
        $this->assertSame('ok', $fresh->sync_status->value);
        $this->assertSame('"v1"', $fresh->events_etag);
    }

    public function test_syncing_twice_never_double_counts_xp(): void
    {
        Http::fake(['*' => Http::response($this->githubEvents(), 200, ['ETag' => '"v1"'])]);

        $user = User::factory()->create();

        app(SyncService::class)->syncUser($user);
        $eventsAfterFirst = ActivityEvent::query()->where('user_id', $user->id)->count();
        $xpAfterFirst = $user->fresh()->total_xp;

        app(SyncService::class)->syncUser($user->fresh());

        $this->assertSame($eventsAfterFirst, ActivityEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame($xpAfterFirst, $user->fresh()->total_xp);
        $this->assertSame(1, Sanctum::actingAs($user) ? 1 : 1); // no-op: keeps the assertion list readable
    }

    public function test_a_second_sync_inside_the_cooldown_is_throttled(): void
    {
        Http::fake(['*' => Http::response($this->githubEvents(), 200)]);

        $user = User::factory()->create();

        $this->withAuth($user)->postJson('/api/v1/me/sync')->assertStatus(202);

        $this->withAuth($user)
            ->postJson('/api/v1/me/sync')
            ->assertStatus(429)
            ->assertJsonPath('status', 'throttled')
            ->assertHeader('Retry-After');

        $this->travel(6)->minutes();

        $this->withAuth($user)->postJson('/api/v1/me/sync')->assertStatus(202);
    }

    public function test_a_rate_limited_github_response_is_recorded_instead_of_crashing(): void
    {
        Http::fake(['*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => '60',
        ])]);

        $user = User::factory()->create();

        $this->withAuth($user)->postJson('/api/v1/me/sync')->assertStatus(202);

        $run = SyncRun::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('rate_limited', $run->status);
        $this->assertSame(0, ActivityEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame('ok', $user->fresh()->sync_status->value);
    }

    public function test_it_backs_off_before_calling_github_when_the_quota_is_low(): void
    {
        Http::fake(['*' => Http::response($this->githubEvents(), 200)]);

        $user = User::factory()->create();
        Cache::put('github:rate_remaining:'.$user->id, 10, now()->addHour());

        $this->withAuth($user)->postJson('/api/v1/me/sync')->assertStatus(202);

        Http::assertNothingSent();
        $this->assertSame('rate_limited', SyncRun::query()->where('user_id', $user->id)->firstOrFail()->status);
    }

    public function test_a_revoked_token_flags_the_account_for_reauth(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $user = User::factory()->create();

        $this->withAuth($user)->postJson('/api/v1/me/sync')->assertStatus(202);

        $this->assertSame('needs_reauth', $user->fresh()->sync_status->value);
        $this->assertSame('needs_reauth', SyncRun::query()->where('user_id', $user->id)->firstOrFail()->status);
    }

    public function test_the_activity_feed_is_paginated_newest_first(): void
    {
        $user = User::factory()->create();

        $oldest = $this->seedEvent($user, '2026-10-01 10:00:00');
        $newest = $this->seedEvent($user, '2026-10-20 10:00:00');
        $middle = $this->seedEvent($user, '2026-10-10 10:00:00');

        $response = $this->withAuth($user)->getJson('/api/v1/me/activity');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $oldest->id)
            ->assertJsonPath('data.0.xp_awarded', 5)
            ->assertJsonStructure(['data' => [['id', 'type', 'repo_full_name', 'occurred_at', 'xp_awarded']], 'sync' => ['state', 'last_synced_at']]);

        $this->assertSame('idle', $response->json('sync.state'));
    }

    public function test_the_activity_feed_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/activity')->assertUnauthorized();
        $this->postJson('/api/v1/me/sync')->assertUnauthorized();
    }

    private function seedEvent(User $user, string $occurredAt): ActivityEvent
    {
        return ActivityEvent::query()->create([
            'user_id' => $user->id,
            'github_event_id' => 'evt-'.uniqid('', true),
            'type' => 'commit',
            'repo_full_name' => 'acme/app',
            'occurred_at' => $occurredAt,
            'metadata' => ['message' => 'fix: something'],
            'xp_awarded' => 5,
            'awarded_at' => now('UTC'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function githubEvents(): array
    {
        return [
            [
                'id' => '4000000001',
                'type' => 'PushEvent',
                'repo' => ['name' => 'octocat/hello-world'],
                'created_at' => now('UTC')->subHour()->toIso8601String(),
                'payload' => [
                    'commits' => [[
                        'sha' => 'abc123',
                        'message' => 'feat: add the thing',
                        'author' => ['name' => 'Octo Cat'],
                        'url' => 'https://github.com/octocat/hello-world/commit/abc123',
                    ]],
                ],
            ],
            [
                'id' => '4000000002',
                'type' => 'PullRequestEvent',
                'repo' => ['name' => 'octocat/hello-world'],
                'created_at' => now('UTC')->subMinutes(30)->toIso8601String(),
                'payload' => [
                    'action' => 'opened',
                    'pull_request' => [
                        'number' => 7,
                        'title' => 'Add the thing',
                        'html_url' => 'https://github.com/octocat/hello-world/pull/7',
                        'merged' => false,
                    ],
                ],
            ],
        ];
    }

    private function withAuth(User $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('web')->plainTextToken]);
    }
}
