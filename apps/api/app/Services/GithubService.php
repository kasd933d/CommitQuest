<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Every GitHub HTTP call in the app goes through this class (PRD §10) so tests
 * can replace the whole integration with `Http::fake()`.
 *
 * Endpoints used:
 *  - GET /users/{username}/events/public  (conditional, with ETag)
 *  - GET /repos/{owner}/{repo}            (fork detection, cached 24h)
 *  - GET /repos/{owner}/{repo}/compare/{before}...{head}  (push contents)
 *  - GET /repos/{owner}/{repo}/commits?sha={head}         (new-ref fallback)
 */
class GithubService
{
    public const BASE_URL = 'https://api.github.com';

    /**
     * Below this remaining quota we stop spending requests on non-essential
     * syncs until the window resets (PRD §10).
     */
    public const MIN_SAFE_REMAINING = 50;

    public function publicEvents(User $user): GithubEventsResult
    {
        if (! $user->github_token) {
            return new GithubEventsResult('unauthorized', error: 'No GitHub token stored for this account.');
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ($user->events_etag) {
            $headers['If-None-Match'] = $user->events_etag;
        }

        $response = Http::withToken($user->github_token)
            ->withHeaders($headers)
            ->connectTimeout(5)
            ->timeout(15)
            ->get(self::BASE_URL.'/users/'.rawurlencode($user->username).'/events/public');

        return $this->interpret($response, $user->events_etag);
    }

    /**
     * PRD §4.3: events from repos the user owns that are forks are ignored.
     * The owner check is free, so the HTTP call only happens for repos the
     * user actually owns, and the answer is cached for a day.
     */
    public function isForkOwnedByUser(User $user, string $repoFullName): bool
    {
        $owner = Str::before($repoFullName, '/');

        if (strcasecmp($owner, $user->username) !== 0) {
            return false;
        }

        $fork = Cache::remember(
            'github:repo:fork:'.strtolower($repoFullName),
            now()->addDay(),
            function () use ($user, $repoFullName) {
                if (! $user->github_token) {
                    return null;
                }

                $response = Http::withToken($user->github_token)
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->get(self::BASE_URL.'/repos/'.$repoFullName);

                if (! $response->successful()) {
                    return null; // transient failure: do not cache a guess
                }

                return (bool) $response->json('fork', false);
            },
        );

        return (bool) $fork;
    }

    /**
     * The Events API stopped shipping the commit list inside PushEvent
     * payloads (2026: only ref/head/before), so the contents of a push are
     * resolved here (PRD §4.2).
     *
     *  - `before...head` via the Compare API returns exactly the pushed range.
     *  - A brand-new ref reports an all-zero `before` (nothing to compare
     *    against, 404), so the commits reachable from `head` are listed
     *    instead; ActivityNormalizer drops any sha it already counted, which
     *    keeps that looser listing honest.
     *
     * @return list<array<string, mixed>>
     */
    public function pushCommits(User $user, string $repoFullName, string $before, string $head): array
    {
        if (! $user->github_token || $head === '') {
            return [];
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $request = fn (string $url, array $query = []) => Http::withToken($user->github_token)
            ->withHeaders($headers)
            ->connectTimeout(5)
            ->timeout(15)
            ->get($url, $query);

        if ($before !== '' && trim($before, '0') !== '') {
            $response = $request(self::BASE_URL.'/repos/'.$repoFullName.'/compare/'.$before.'...'.$head);

            if ($response->successful()) {
                $commits = $response->json('commits');

                if (is_array($commits)) {
                    return $commits;
                }
                // Malformed body: fall through to the head listing below.
            }
            // Anything but 200 here (deleted `before`, odd force-push) falls
            // back to the head listing below rather than losing the push.
        }

        // 20 = matches ActivityNormalizer::MAX_COMMITS_PER_PUSH.
        $response = $request(self::BASE_URL.'/repos/'.$repoFullName.'/commits', [
            'sha' => $head,
            'per_page' => 20,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $commits = $response->json();

        return is_array($commits) ? $commits : [];
    }

    private function interpret(Response $response, ?string $previousEtag): GithubEventsResult
    {
        $remaining = $this->headerInt($response, 'X-RateLimit-Remaining');
        $status = $response->status();

        if ($status === 304) {
            return new GithubEventsResult('not_modified', etag: $previousEtag, rateLimitRemaining: $remaining);
        }

        if ($status === 200) {
            $events = $response->json();

            return new GithubEventsResult(
                status: 'ok',
                events: is_array($events) ? $events : [],
                etag: $response->header('ETag') ?: $previousEtag,
                rateLimitRemaining: $remaining,
            );
        }

        if ($status === 401) {
            return new GithubEventsResult('unauthorized', rateLimitRemaining: $remaining,
                error: 'GitHub rejected the stored token.');
        }

        if ($status === 403 || $status === 429) {
            $retryAfter = $this->headerInt($response, 'Retry-After');
            $looksLikeRateLimit = $remaining === 0
                || $retryAfter !== null
                || Str::contains(strtolower($response->body()), 'rate limit');

            if ($looksLikeRateLimit) {
                return new GithubEventsResult(
                    status: 'rate_limited',
                    rateLimitRemaining: $remaining,
                    retryAfter: $retryAfter,
                    error: 'GitHub rate limit reached, backing off.',
                );
            }

            return new GithubEventsResult('unauthorized', rateLimitRemaining: $remaining,
                error: 'GitHub refused the request.');
        }

        return new GithubEventsResult(
            status: 'error',
            rateLimitRemaining: $remaining,
            error: $this->message($response, $status),
        );
    }

    private function headerInt(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return $value === '' || $value === null ? null : (int) $value;
    }

    private function message(Response $response, int $status): string
    {
        $body = $response->json('message');

        return is_string($body) && $body !== ''
            ? "GitHub responded {$status}: {$body}"
            : "GitHub responded {$status}.";
    }
}
