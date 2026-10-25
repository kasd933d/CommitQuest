<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_profile_with_level_progress(): void
    {
        $user = User::factory()->withXp(450)->withStreak(4, 9)->create();

        $response = $this->withAuth($user)->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.total_xp', 450)
            ->assertJsonPath('data.level', 3)
            ->assertJsonPath('data.current_streak', 4)
            ->assertJsonPath('data.longest_streak', 9)
            ->assertJsonPath('data.xp_at_next_level', 900)
            ->assertJsonPath('data.sync_status', 'ok');

        // Level 3 spans 400..900 XP, so 450 XP is 10% of the way through.
        $response->assertJsonPath('data.level_progress', 10)
            ->assertJsonPath('data.xp_to_next_level', 450);
    }

    public function test_the_token_is_never_returned(): void
    {
        $user = User::factory()->create();

        $json = $this->withAuth($user)->getJson('/api/v1/me')->json('data');

        $this->assertArrayNotHasKey('github_token', $json);
        $this->assertArrayNotHasKey('events_etag', $json);
        $this->assertStringNotContainsString($user->github_token, json_encode($json));
    }

    public function test_it_updates_timezone_and_visibility(): void
    {
        $user = User::factory()->create();

        $this->withAuth($user)
            ->patchJson('/api/v1/me', ['timezone' => 'Asia/Karachi', 'is_public' => false])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Karachi')
            ->assertJsonPath('data.is_public', false);

        $this->assertSame('Asia/Karachi', $user->fresh()->timezone);
        $this->assertFalse($user->fresh()->is_public);
    }

    public function test_it_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create();

        $this->withAuth($user)
            ->patchJson('/api/v1/me', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    public function test_it_hard_deletes_the_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson('/api/v1/me')
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    private function withAuth(User $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('web')->plainTextToken]);
    }
}
