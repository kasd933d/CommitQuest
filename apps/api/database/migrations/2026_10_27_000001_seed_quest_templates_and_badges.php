<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reference data for quests and badges (PRD §4.5, §4.6).
 *
 * Seeded from a migration instead of a seeder so every environment — local,
 * test (RefreshDatabase) and production — has the templates without an extra
 * operational step. Upserts make the migration safe to re-run when templates
 * are tuned later.
 */
return new class extends Migration
{
    /** @return array<int, array<string, mixed>> */
    private function questTemplates(): array
    {
        $now = now('UTC');

        $rows = [
            // daily (PRD §4.5 example table + companions)
            ['daily_commit_1', 'First Blood Draw', 'Land 1 commit today.', 'daily', 'commits', 1, 15],
            ['daily_commit_3', 'Triple Strike', 'Land 3 commits today.', 'daily', 'commits', 3, 30],
            ['daily_commit_5', 'Code Slinger', 'Land 5 commits today.', 'daily', 'commits', 5, 50],
            ['daily_review_1', 'Second Pair of Eyes', 'Review 1 pull request today.', 'daily', 'pr_review', 1, 40],
            ['daily_review_2', 'Critique Combo', 'Review 2 pull requests today.', 'daily', 'pr_review', 2, 60],
            ['daily_pr_open_1', 'Open the Gate', 'Open 1 pull request today.', 'daily', 'pr_opened', 1, 40],
            ['daily_merge_1', 'Ship It', 'Merge 1 pull request today.', 'daily', 'pr_merged', 1, 45],
            ['daily_issue_close_1', 'Cleanup Duty', 'Close 1 issue today.', 'daily', 'issue_closed', 1, 30],
            ['daily_two_repos', 'World Hopper', 'Contribute to 2 different repos today.', 'daily', 'distinct_repos', 2, 35],
            // weekly (1 is drawn as the weekly boss each week)
            ['weekly_merge_3', 'Boss: Merge Mauler', 'Merge 3 pull requests this week.', 'weekly', 'pr_merged', 3, 200],
            ['weekly_5_days', 'Boss: Five Day Streak', 'Be active on 5 days this week.', 'weekly', 'active_days', 5, 250],
            ['weekly_commit_15', 'Boss: Grind Core', 'Land 15 commits this week.', 'weekly', 'commits', 15, 180],
            ['weekly_review_5', 'Boss: Review Rush', 'Review 5 pull requests this week.', 'weekly', 'pr_review', 5, 150],
        ];

        return array_map(fn (array $row) => [
            'key' => $row[0],
            'title' => $row[1],
            'description' => $row[2],
            'period' => $row[3],
            'metric' => $row[4],
            'target' => $row[5],
            'xp_reward' => $row[6],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function badges(): array
    {
        $now = now('UTC');

        $rows = [
            ['first_blood', 'First Blood', 'Earn your first qualifying activity.', '🩸', 'common'],
            ['week_warrior', 'Week Warrior', 'Keep a 7-day streak.', '⚔️', 'rare'],
            ['monthly_monk', 'Monthly Monk', 'Keep a 30-day streak.', '🧘', 'epic'],
            ['merge_master', 'Merge Master', 'Merge 25 pull requests.', '🔀', 'epic'],
            ['code_reviewer', 'Code Reviewer', 'Leave 20 pull request reviews.', '🔎', 'rare'],
            ['night_owl', 'Night Owl', '10 commits between 00:00 and 04:00 local time.', '🦉', 'rare'],
            ['early_bird', 'Early Bird', '10 commits between 04:00 and 07:00 local time.', '🐦', 'rare'],
            ['explorer', 'Explorer', 'Be active in 10 different repositories.', '🗺️', 'rare'],
            ['level_10', 'Level 10', 'Reach level 10.', '🔟', 'epic'],
            ['quest_slayer', 'Quest Slayer', 'Complete 25 quests.', '🗡️', 'epic'],
            ['boss_slayer', 'Boss Slayer', 'Complete 5 weekly boss quests.', '👑', 'legendary'],
            ['guild_founder', 'Guild Founder', 'Create a guild.', '🏰', 'rare'],
        ];

        return array_map(fn (array $row) => [
            'key' => $row[0],
            'name' => $row[1],
            'description' => $row[2],
            'icon' => $row[3],
            'rarity' => $row[4],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('quest_templates')->upsert($this->questTemplates(), ['key'], [
            'title', 'description', 'period', 'metric', 'target', 'xp_reward', 'is_active', 'updated_at',
        ]);

        DB::table('badges')->upsert($this->badges(), ['key'], [
            'name', 'description', 'icon', 'rarity', 'updated_at',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Reference rows are left in place: rolling back the schema removes the
     * tables anyway.
     */
    public function down(): void {}
};
