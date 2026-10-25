<?php

namespace App\Services;

/**
 * Result of reading the GitHub public Events API.
 *
 * Statuses:
 *  - ok            fresh events in `events`, `etag` to store for the next poll
 *  - not_modified  GitHub answered 304, nothing changed (costs no rate limit)
 *  - rate_limited  403/429 or remaining quota below the safe threshold - back off
 *  - unauthorized  401 or revoked OAuth app - flag the account needs_reauth
 *  - error         any other failure
 */
final readonly class GithubEventsResult
{
    public function __construct(
        public string $status,
        public array $events = [],
        public ?string $etag = null,
        public ?int $rateLimitRemaining = null,
        public ?int $retryAfter = null,
        public ?string $error = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
