<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\UserQuestResource;
use App\Models\User;
use App\Services\GuildService;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    /**
     * Public profile (PRD §4.8): level, XP, streak, badges, heatmap and
     * recent quests. Private profiles 404 to avoid confirming existence.
     */
    public function profile(string $username, StreakService $streaks): JsonResponse
    {
        $user = User::query()
            ->where('username', $username)
            ->where('is_public', true)
            ->first();

        if ($user === null) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $unlockedAt = $user->badges()->pluck('unlocked_at', 'badge_id');

        $badges = $user->badges()->get()
            ->each(function ($badge) use ($unlockedAt): void {
                $badge->setAttribute('is_unlocked', true);
                $badge->setAttribute('unlocked_at', $unlockedAt[$badge->id] ?? null);
            });

        $recentQuests = $user->quests()
            ->with('template')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            'username' => $user->username,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
            'total_xp' => $user->total_xp,
            'level' => $user->level,
            'current_streak' => $user->current_streak,
            'longest_streak' => $user->longest_streak,
            'streak_freezes' => $user->streak_freezes,
            'joined_at' => $user->created_at?->toIso8601String(),
            'heatmap' => $streaks->heatmap($user),
            'badges' => BadgeResource::collection($badges)->resolve(),
            'recent_quests' => UserQuestResource::collection($recentQuests)->resolve(),
        ]);
    }

    /**
     * Global leaderboard (PRD §4.7): top 100 by weekly or total XP.
     */
    public function leaderboard(Request $request, GuildService $guilds): JsonResponse
    {
        $scope = $request->query('scope', 'weekly');

        if (! in_array($scope, ['weekly', 'all'], true)) {
            return response()->json(['message' => 'scope must be weekly or all.'], 422);
        }

        return response()->json([
            'scope' => $scope,
            'week_start' => $scope === 'weekly'
                ? now('UTC')->startOfWeek()->toIso8601String()
                : null,
            'entries' => $guilds->globalLeaderboard($scope),
        ]);
    }

    /**
     * Global counters for the landing page (PRD §4.8/M5).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'users' => (int) DB::table('users')->count(),
            'total_xp' => (int) DB::table('users')->sum('total_xp'),
            'commits' => (int) DB::table('activity_events')->where('type', 'commit')->count(),
            'pull_requests' => (int) DB::table('activity_events')->whereIn('type', ['pr_opened', 'pr_merged'])->count(),
            'quests_completed' => (int) DB::table('user_quests')->where('status', 'completed')->count(),
            'badges_unlocked' => (int) DB::table('user_badges')->count(),
            'guilds' => (int) DB::table('guilds')->count(),
        ]);
    }
}
