<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Models\User;
use App\Support\Levels;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = fake()->unique()->userName();

        return [
            'github_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999_999),
            'username' => $handle,
            'display_name' => fake()->name(),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/'.fake()->numberBetween(1, 999999),
            'github_token' => 'gho_'.fake()->regexify('[A-Za-z0-9]{20}'),
            'timezone' => 'UTC',
            'is_public' => true,
            'total_xp' => 0,
            'level' => 1,
            'current_streak' => 0,
            'longest_streak' => 0,
            'last_active_date' => null,
            'streak_freezes' => 0,
            'events_etag' => null,
            'last_synced_at' => null,
            'sync_status' => SyncStatus::Ok,
            'sync_error' => null,
        ];
    }

    /**
     * Give the user a total XP balance (level is derived, like XpService does).
     */
    public function withXp(int $totalXp): static
    {
        return $this->state(fn (array $attributes) => [
            'total_xp' => $totalXp,
            'level' => Levels::fromXp($totalXp),
        ]);
    }

    /**
     * Give the user a streak.
     */
    public function withStreak(int $current, ?int $longest = null): static
    {
        return $this->state(fn (array $attributes) => [
            'current_streak' => $current,
            'longest_streak' => $longest ?? $current,
            'last_active_date' => now()->toDateString(),
        ]);
    }

    /**
     * Hide the profile from the public leaderboard and profile page.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }
}
