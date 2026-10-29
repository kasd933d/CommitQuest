<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    // ---- Heatmap ----------------------------------------------------------

    public function test_heatmap_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/heatmap')->assertUnauthorized();
    }

    public function test_heatmap_returns_twelve_weeks(): void
    {
        $user = User::factory()->create();

        $days = $this->withAuth($user)->getJson('/api/v1/me/heatmap')->assertOk()->json('days');

        $this->assertCount(12 * 7, $days);
        $this->assertArrayHasKey('date', $days[0]);
        $this->assertArrayHasKey('count', $days[0]);
    }

    // ---- Quests -----------------------------------------------------------

    public function test_quests_endpoint_assigns_and_returns_current_quests(): void
    {
        $user = User::factory()->create();

        $json = $this->withAuth($user)->getJson('/api/v1/me/quests')->assertOk()->json();

        $this->assertCount(3, array_filter($json['current'], fn ($q) => $q['period'] === 'daily'));
        $this->assertCount(1, array_filter($json['current'], fn ($q) => $q['period'] === 'weekly'));
        $this->assertIsArray($json['recent']);

        $quest = $json['current'][0];
        foreach (['id', 'key', 'title', 'description', 'period', 'metric', 'progress', 'target', 'xp_reward', 'status', 'period_start', 'period_end'] as $field) {
            $this->assertArrayHasKey($field, $quest);
        }
    }

    public function test_quests_endpoint_is_idempotent(): void
    {
        $user = User::factory()->create();

        $first = $this->withAuth($user)->getJson('/api/v1/me/quests')->json('current');
        $second = $this->withAuth($user)->getJson('/api/v1/me/quests')->json('current');

        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'));
        $this->assertSame(4, $user->quests()->count());
    }

    // ---- Badges -----------------------------------------------------------

    public function test_badges_endpoint_returns_every_badge_with_lock_state(): void
    {
        $user = User::factory()->create();

        $data = $this->withAuth($user)->getJson('/api/v1/me/badges')->assertOk()->json('data');

        $this->assertCount(Badge::query()->count(), $data);
        $this->assertFalse($data[0]['unlocked']);
        $this->assertNull($data[0]['unlocked_at']);
        $this->assertArrayNotHasKey('github_token', $data[0]);
    }

    public function test_badges_endpoint_marks_unlocked_badges(): void
    {
        $user = User::factory()->create();
        $badge = Badge::query()->where('key', 'first_blood')->firstOrFail();

        $user->badges()->attach($badge->id, ['unlocked_at' => now('UTC')]);

        $data = $this->withAuth($user)->getJson('/api/v1/me/badges')->assertOk()->json('data');
        $firstBlood = collect($data)->firstWhere('key', 'first_blood');

        $this->assertTrue($firstBlood['unlocked']);
        $this->assertNotNull($firstBlood['unlocked_at']);
    }

    // ---- Notifications ----------------------------------------------------

    public function test_notifications_are_listed_with_the_unread_count(): void
    {
        $user = User::factory()->create();

        // Both rows land in the same second; ordering falls back to id.
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_LEVEL_UP,
            'title' => 'Level up! You reached level 2',
            'body' => 'You went from level 1 to level 2.',
            'read_at' => now('UTC'),
        ]);
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_QUEST_COMPLETE,
            'title' => 'Quest complete: Triple Strike',
            'body' => 'You earned 30 XP.',
        ]);

        $response = $this->withAuth($user)->getJson('/api/v1/me/notifications')->assertOk();

        $this->assertSame(1, $response->json('unread_count'));
        $this->assertCount(2, $response->json('data'));

        // Newest first.
        $this->assertSame(Notification::TYPE_QUEST_COMPLETE, $response->json('data.0.type'));
    }

    public function test_marking_notifications_read_updates_the_unread_count(): void
    {
        $user = User::factory()->create();

        $read = Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_LEVEL_UP,
            'title' => 'Level up!',
            'body' => 'Level 2.',
        ]);
        $keep = Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_QUEST_COMPLETE,
            'title' => 'Quest complete',
            'body' => '+30 XP',
        ]);

        // Mark only the first one read.
        $response = $this->withAuth($user)
            ->postJson('/api/v1/me/notifications/read', ['ids' => [$read->id]])
            ->assertOk();

        $this->assertSame(1, $response->json('unread_count'));
        $this->assertNotNull($read->fresh()->read_at);
        $this->assertNull($keep->fresh()->read_at);

        // Omitting ids marks everything read.
        $response = $this->withAuth($user)
            ->postJson('/api/v1/me/notifications/read')
            ->assertOk();

        $this->assertSame(0, $response->json('unread_count'));
    }

    public function test_notifications_are_scoped_to_the_signed_in_user(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        Notification::query()->create([
            'user_id' => $other->id,
            'type' => Notification::TYPE_LEVEL_UP,
            'title' => 'Other user level up',
            'body' => 'Not yours.',
        ]);

        $response = $this->withAuth($me)->getJson('/api/v1/me/notifications')->assertOk();

        $this->assertSame(0, $response->json('unread_count'));
        $this->assertCount(0, $response->json('data'));
    }

    private function withAuth(User $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('web')->plainTextToken]);
    }
}
