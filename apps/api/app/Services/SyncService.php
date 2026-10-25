<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SyncStatus;
use App\Jobs\ProcessActivityEvent;
use App\Models\ActivityEvent;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Pulls recent GitHub events for a user, normalizes them into `activity_events`
 * and queues one job per new event.
 *
 * Idempotent by construction: events are matched on `github_event_id`, which is
 * unique, so syncing twice in a row can never double-count XP (PRD §4.2).
 */
class SyncService
{
    /** How long the last known rate-limit quota is reused between syncs. */
    public const RATE_CACHE_SECONDS = 3600;

    public function __construct(
        private readonly GithubService $github,
        private readonly ActivityNormalizer $normalizer,
    ) {}

    /**
     * @return array{status: string, fetched: int, new_events: int, message: string|null}
     */
    public function syncUser(User $user): array
    {
        if (! $user->github_token) {
            return $this->skip($user, 'no_token');
        }

        // Back off before spending a request if the last known quota is low.
        $cachedRemaining = Cache::get($this->rateKey($user->id));

        if ($cachedRemaining !== null && $cachedRemaining < GithubService::MIN_SAFE_REMAINING) {
            $run = $this->finishRun($user, null, 'rate_limited', 0, 0,
                'Rate limit quota below '.GithubService::MIN_SAFE_REMAINING.', backing off.');

            return $this->result($run);
        }

        $run = SyncRun::query()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        try {
            $result = $this->github->publicEvents($user);
        } catch (\Throwable $e) {
            $this->finishRun($user, $run, 'error', 0, 0, $this->sanitize($e->getMessage()));
            $user->forceFill([
                'sync_status' => SyncStatus::Error,
                'sync_error' => 'GitHub request failed.',
            ])->save();
            report($e);

            return ['status' => 'error', 'fetched' => 0, 'new_events' => 0, 'message' => 'request_failed'];
        }

        if ($result->rateLimitRemaining !== null) {
            Cache::put($this->rateKey($user->id), $result->rateLimitRemaining, now()->addSeconds(self::RATE_CACHE_SECONDS));
        }

        if ($result->etag !== null && $result->status !== 'not_modified') {
            $user->events_etag = $result->etag;
        }

        return match ($result->status) {
            'ok' => $this->store($user, $run, $result),
            'not_modified' => $this->notModified($user, $run, $result),
            'rate_limited' => $this->rateLimited($user, $run, $result),
            'unauthorized' => $this->unauthorized($user, $run, $result),
            default => $this->failed($user, $run, $result),
        };
    }

    private function store(User $user, SyncRun $run, GithubEventsResult $result): array
    {
        $rows = $this->normalizer->normalize($user, $result->events);
        $new = 0;

        foreach ($rows as $row) {
            if (ActivityEvent::query()->where('github_event_id', $row['github_event_id'])->exists()) {
                continue;
            }

            $created = ActivityEvent::query()->create($row + ['user_id' => $user->id]);
            ProcessActivityEvent::dispatch($created->id);
            $new++;
        }

        $user->forceFill([
            'last_synced_at' => now('UTC'),
            'sync_status' => SyncStatus::Ok,
            'sync_error' => null,
            'events_etag' => $result->etag ?? $user->events_etag,
        ])->save();

        $this->finishRun($user, $run, 'ok', \count($result->events), $new, null, $result->rateLimitRemaining);

        return ['status' => 'ok', 'fetched' => \count($result->events), 'new_events' => $new, 'message' => null];
    }

    private function notModified(User $user, SyncRun $run, GithubEventsResult $result): array
    {
        $user->forceFill(['last_synced_at' => now('UTC'), 'sync_status' => SyncStatus::Ok, 'sync_error' => null])->save();
        $this->finishRun($user, $run, 'not_modified', 0, 0, null, $result->rateLimitRemaining);

        return ['status' => 'not_modified', 'fetched' => 0, 'new_events' => 0, 'message' => null];
    }

    private function rateLimited(User $user, SyncRun $run, GithubEventsResult $result): array
    {
        // Transient: the dashboard shows the last error, the schedule retries.
        $user->forceFill(['sync_error' => 'GitHub rate limit reached, retrying later.'])->save();

        $this->finishRun($user, $run, 'rate_limited', 0, 0, $result->error, $result->rateLimitRemaining);

        return ['status' => 'rate_limited', 'fetched' => 0, 'new_events' => 0, 'message' => 'rate_limited'];
    }

    private function unauthorized(User $user, SyncRun $run, GithubEventsResult $result): array
    {
        $user->forceFill([
            'sync_status' => SyncStatus::NeedsReauth,
            'sync_error' => 'GitHub access was revoked. Sign in again to keep syncing.',
        ])->save();
        $this->finishRun($user, $run, 'needs_reauth', 0, 0, $result->error, $result->rateLimitRemaining);

        return ['status' => 'needs_reauth', 'fetched' => 0, 'new_events' => 0, 'message' => 'needs_reauth'];
    }

    private function failed(User $user, SyncRun $run, GithubEventsResult $result): array
    {
        $user->forceFill([
            'sync_status' => SyncStatus::Error,
            'sync_error' => $this->sanitize((string) ($result->error ?? 'GitHub sync failed.')),
        ])->save();
        $this->finishRun($user, $run, 'error', 0, 0, $result->error, $result->rateLimitRemaining);

        return ['status' => 'error', 'fetched' => 0, 'new_events' => 0, 'message' => 'error'];
    }

    private function skip(User $user, string $reason): array
    {
        return ['status' => 'skipped', 'fetched' => 0, 'new_events' => 0, 'message' => $reason];
    }

    /**
     * @return array{status: string, fetched: int, new_events: int, message: string|null}
     */
    private function result(SyncRun $run): array
    {
        return ['status' => $run->status, 'fetched' => 0, 'new_events' => 0, 'message' => $run->error];
    }

    private function finishRun(
        User $user,
        ?SyncRun $run,
        string $status,
        int $fetched,
        int $new,
        ?string $error,
        ?int $remaining = null,
    ): SyncRun {
        $run ??= SyncRun::query()->make([
            'user_id' => $user->id,
            'started_at' => now('UTC'),
        ]);

        $run->forceFill([
            'status' => $status,
            'events_fetched' => $fetched,
            'events_new' => $new,
            'finished_at' => now('UTC'),
            'error' => $this->sanitize((string) ($error ?? '')) ?: null,
            'rate_limit_remaining' => $remaining,
        ])->save();

        return $run;
    }

    private function rateKey(int $userId): string
    {
        return 'github:rate_remaining:'.$userId;
    }

    /**
     * Never let anything that might contain a token reach the database or logs.
     */
    private function sanitize(string $message): ?string
    {
        if ($message === '') {
            return null;
        }

        $clean = \preg_replace('/ghp_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+/', '[redacted]', $message) ?? $message;

        return \mb_substr($clean, 0, 500);
    }
}
