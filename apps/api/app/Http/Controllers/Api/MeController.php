<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarkNotificationsReadRequest;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\ActivityEventResource;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\UserQuestResource;
use App\Http\Resources\UserResource;
use App\Jobs\SyncGithubEvents;
use App\Models\Badge;
use App\Services\NotificationService;
use App\Services\QuestService;
use App\Services\StreakService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class MeController extends Controller
{
    /** Minimum seconds between two sync requests for the same user. */
    public const SYNC_COOLDOWN_SECONDS = 300;

    /**
     * The signed-in user's profile, XP, level and streak.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Update settings (timezone, profile visibility).
     */
    public function update(UpdateMeRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user()->fresh());
    }

    /**
     * Hard delete the account and everything attached to it (PRD §4.1).
     * Child rows are removed by ON DELETE CASCADE foreign keys.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Tokens first: Sanctum's FK is not guaranteed to cascade on every driver.
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account and all data deleted.']);
    }

    /**
     * Paginated activity feed (newest first) with the current sync state.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();

        $events = $user->activityEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return ActivityEventResource::collection($events)
            ->additional([
                'sync' => $this->syncState($user->id, $user->last_synced_at?->toIso8601String()),
            ])
            ->response();
    }

    /**
     * Queue a GitHub events sync (PRD §4.3: throttled to once per 5 minutes).
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        $lockKey = 'sync:lock:'.$user->id;

        if (Cache::add($lockKey, 1, self::SYNC_COOLDOWN_SECONDS)) {
            SyncGithubEvents::dispatch($user->id);

            return response()->json([
                'status' => 'queued',
                'queued_at' => now('UTC')->toIso8601String(),
                'cooldown_seconds' => self::SYNC_COOLDOWN_SECONDS,
            ], Response::HTTP_ACCEPTED);
        }

        $retryAfter = (int) Cache::get($lockKey, self::SYNC_COOLDOWN_SECONDS);

        return response()->json([
            'status' => 'throttled',
            'message' => 'Sync already requested recently. Try again later.',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }

    /**
     * 12-week activity heatmap (PRD §4.4).
     */
    public function heatmap(Request $request, StreakService $streaks): JsonResponse
    {
        return response()->json([
            'days' => $streaks->heatmap($request->user()),
        ]);
    }

    /**
     * Current (live) and recent quests. Assigns anything due first, so a
     * fresh account sees quests immediately instead of waiting for the
     * scheduler tick (assignment is idempotent).
     */
    public function quests(Request $request, QuestService $quests): JsonResponse
    {
        $user = $request->user();

        $quests->assignDue($user);

        $today = CarbonImmutable::now($this->timezoneOf($user->timezone))->toDateString();

        $current = $user->quests()
            ->with('template')
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->orderBy('id')
            ->get();

        $recent = $user->quests()
            ->with('template')
            ->where('period_end', '<', $today)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'current' => UserQuestResource::collection($current)->resolve(),
            'recent' => UserQuestResource::collection($recent)->resolve(),
        ]);
    }

    /**
     * Every badge with the signed-in user's unlocked state (PRD §4.6).
     */
    public function badges(Request $request): JsonResponse
    {
        $user = $request->user();

        $unlockedAt = $user->badges()->pluck('unlocked_at', 'badge_id');

        $badges = Badge::query()
            ->orderBy('id')
            ->get()
            ->each(function (Badge $badge) use ($unlockedAt): void {
                $badge->setAttribute('is_unlocked', $unlockedAt->has($badge->id));
                $badge->setAttribute('unlocked_at', $unlockedAt[$badge->id] ?? null);
            });

        return response()->json([
            'data' => BadgeResource::collection($badges)->resolve(),
        ]);
    }

    /**
     * Paginated notifications plus the unread count the bell needs (PRD §4.9).
     */
    public function notifications(Request $request, NotificationService $notifications): JsonResponse
    {
        $page = $notifications->paginate(
            $request->user(),
            min((int) $request->query('per_page', 20), 50),
        );

        return NotificationResource::collection($page)
            ->additional(['unread_count' => $notifications->unreadCount($request->user())])
            ->response();
    }

    /**
     * Mark the listed ids (or all unread) notifications as read.
     */
    public function readNotifications(MarkNotificationsReadRequest $request, NotificationService $notifications): JsonResponse
    {
        /** @var list<int>|null $ids */
        $ids = $request->validated('ids');

        $unread = $notifications->markRead($request->user(), $ids);

        return response()->json(['unread_count' => $unread]);
    }

    /**
     * @return array{state: string, last_synced_at: string|null}
     */
    private function syncState(int $userId, ?string $lastSyncedAt): array
    {
        $pending = Cache::get('sync:lock:'.$userId) !== null;

        return [
            'state' => $pending ? 'cooldown' : 'idle',
            'last_synced_at' => $lastSyncedAt,
        ];
    }

    private function timezoneOf(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        return \in_array($timezone, \timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
