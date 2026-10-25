<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\ActivityEventResource;
use App\Http\Resources\UserResource;
use App\Jobs\SyncGithubEvents;
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
}
