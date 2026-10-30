<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Badge;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\XpLedger;
use App\Services\QuestService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for local development and screenshots (M5, PRD §14).
 *
 * Creates three public users with plausible activity, XP, streaks, quests,
 * badges and one guild so every screen (dashboard, leaderboard, guild board,
 * public profile) has content without needing a real GitHub account.
 *
 * Deterministic: usernames are fixed and everything is skipped when they
 * already exist, so it is safe to run repeatedly. Fake users carry a
 * `github_id` above 9_000_000_000 to keep them distinguishable from real
 * OAuth users, and `database/database.sqlite` is the intended target.
 */
class DemoSeeder extends Seeder
{
    /** @var list<array{username: string, display_name: string, xp: int, streak: int}> */
    private const HEROES = [
        ['username' => 'ada-lovelace', 'display_name' => 'Ada Lovelace', 'xp' => 1840, 'streak' => 12],
        ['username' => 'grace-hopper', 'display_name' => 'Grace Hopper', 'xp' => 1220, 'streak' => 7],
        ['username' => 'alan-turing', 'display_name' => 'Alan Turing', 'xp' => 640, 'streak' => 3],
    ];

    public function run(): void
    {
        $users = collect(self::HEROES)->map(fn (array $hero) => $this->makeUser($hero));

        $this->command?->info('Seeded demo users: '.$users->pluck('username')->implode(', '));

        // One guild with all three members.
        $guild = Guild::query()->firstOrCreate(
            ['slug' => 'code-crafters'],
            [
                'name' => 'Code Crafters',
                'description' => 'A demo guild racing up the weekly leaderboard.',
                'owner_id' => $users->first()->id,
                'is_public' => true,
                'invite_code' => 'demodemo10',
            ],
        );

        foreach ($users as $index => $user) {
            GuildMember::query()->firstOrCreate(
                ['guild_id' => $guild->id, 'user_id' => $user->id],
                [
                    'role' => $index === 0 ? GuildMember::ROLE_OWNER : GuildMember::ROLE_MEMBER,
                    'joined_at' => now('UTC')->subDays(14 - $index),
                ],
            );
        }

        $this->command?->info("Seeded demo guild: {$guild->name}");
    }

    private function makeUser(array $hero): User
    {
        $user = User::query()->firstOrCreate(
            ['username' => $hero['username']],
            [
                // Far above the OAuth id space so demos never collide with
                // real accounts.
                'github_id' => 9_000_000_000 + crc32($hero['username']),
                'display_name' => $hero['display_name'],
                'avatar_url' => null,
                'timezone' => 'UTC',
                'is_public' => true,
                'total_xp' => $hero['xp'],
                'level' => max(1, intdiv($hero['xp'], 200) + 1),
                'current_streak' => $hero['streak'],
                'longest_streak' => $hero['streak'] + 4,
                'streak_freezes' => 1,
                'last_active_date' => now('UTC')->toDateString(),
                'github_token' => Str::random(40), // fake; never usable
            ],
        );

        if ($user->wasRecentlyCreated) {
            $this->backfillActivity($user, $hero['xp']);
            $this->unlockSomeBadges($user);
            app(QuestService::class)->assignDue($user);
        }

        return $user;
    }

    /**
     * A week of commit events plus one ledger row per day, so the heatmap,
     * activity feed and leaderboards all have data.
     */
    private function backfillActivity(User $user, int $xp): void
    {
        $repos = ['acme/guitar-hero', 'demo/quest-engine', 'sample/pixel-ui'];

        for ($day = 0; $day < 7; $day++) {
            $occurredAt = now('UTC')->subDays($day)->setTime(10 + $day % 8, 15);

            for ($commit = 0; $commit < ($day % 3) + 1; $commit++) {
                ActivityEvent::query()->create([
                    'user_id' => $user->id,
                    'github_event_id' => "demo-{$user->id}-{$day}-{$commit}",
                    'type' => ActivityType::Commit,
                    'repo_full_name' => $repos[($day + $commit) % 3],
                    'occurred_at' => $occurredAt->addMinutes($commit * 7),
                    'metadata' => [
                        'message' => 'demo: seeded commit',
                        'sha' => Str::lower(Str::random(40)),
                        'url' => null,
                    ],
                ]);
            }

            // One ledger row per day (roughly xp / 7, never zero).
            XpLedger::query()->forceCreate([
                'user_id' => $user->id,
                'source_type' => XpLedger::SOURCE_ACTIVITY,
                'source_id' => $day + 1,
                'amount' => max(5, intdiv($xp, 7)),
                'reason' => 'demo seed',
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        }
    }

    private function unlockSomeBadges(User $user): void
    {
        $first = $user->id === User::query()->orderBy('id')->value('id');

        $keys = $first ? ['first_blood', 'streak_starter', 'merge_master'] : ['first_blood'];

        foreach ($keys as $key) {
            $badgeId = Badge::query()->where('key', $key)->value('id');

            if ($badgeId) {
                UserBadge::query()->firstOrCreate(
                    ['user_id' => $user->id, 'badge_id' => $badgeId],
                    ['unlocked_at' => now('UTC')->subDays(3)],
                );
            }
        }
    }
}
