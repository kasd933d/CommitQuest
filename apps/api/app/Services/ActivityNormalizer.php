<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns raw GitHub Events API payloads into the rows CommitQuest scores.
 *
 * Rules (PRD §4.2):
 *  - PushEvent          -> one row per commit, max 20 per push, empty or
 *                          duplicate messages inside one push are dropped;
 *                          the pushed range is read from the payload when it
 *                          carries `commits`, otherwise resolved through the
 *                          Compare API (GitHub removed the payload list)
 *  - a commit sha is counted at most once per user, ever
 *  - PullRequestEvent   -> pr_opened / pr_merged (only when merged)
 *  - PullRequestReview  -> pr_review
 *  - IssuesEvent closed -> issue_closed
 *  - first event in a repo the user has no history in -> new_repo bonus
 *  - anything older than 90 days is never counted
 *  - forks owned by the user are ignored
 */
class ActivityNormalizer
{
    public const MAX_AGE_DAYS = 90;

    public const MAX_COMMITS_PER_PUSH = 20;

    /** @var array<string, true> commit shas seen earlier in this normalize() call */
    private array $seenShas = [];

    /** @var array<string, array<string, true>> repo -> shas already in activity_events */
    private array $recordedShas = [];

    public function __construct(private readonly GithubService $github) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array{
     *     github_event_id: string,
     *     type: ActivityType,
     *     repo_full_name: string,
     *     occurred_at: CarbonImmutable,
     *     metadata: array<string, mixed>
     * }>
     */
    public function normalize(User $user, array $events): array
    {
        $this->seenShas = [];
        $this->recordedShas = [];

        $rows = [];
        $cutoff = now('UTC')->subDays(self::MAX_AGE_DAYS);

        foreach ($events as $event) {
            $occurredAt = $this->occurredAt($event);
            if ($occurredAt === null || $occurredAt->lessThan($cutoff)) {
                continue;
            }

            $repo = $this->repo($event);
            $eventId = (string) ($event['id'] ?? '');
            if ($repo === null || $eventId === '') {
                continue;
            }

            foreach ($this->rowsFor($user, $event, $eventId, $repo, $occurredAt) as $row) {
                $rows[] = $row;
            }
        }

        $rows = $this->dropOwnedForks($user, $rows);

        return $this->addNewRepoBonuses($user, $rows);
    }

    /**
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function rowsFor(User $user, array $event, string $eventId, string $repo, CarbonImmutable $occurredAt): array
    {
        $type = (string) ($event['type'] ?? '');
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return match ($type) {
            'PushEvent' => $this->commitRows($user, $eventId, $repo, $occurredAt, $payload),
            'PullRequestEvent' => $this->pullRequestRows($eventId, $repo, $occurredAt, $payload),
            'PullRequestReviewEvent' => $this->reviewRows($eventId, $repo, $occurredAt, $payload),
            'IssuesEvent' => $this->issueRows($eventId, $repo, $occurredAt, $payload),
            default => [],
        };
    }

    /**
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function commitRows(User $user, string $eventId, string $repo, CarbonImmutable $occurredAt, array $payload): array
    {
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

        if ($commits === []) {
            // GitHub no longer includes `commits` in PushEvent payloads, so
            // the pushed range is resolved through the API instead.
            $commits = $this->github->pushCommits(
                $user,
                $repo,
                trim((string) ($payload['before'] ?? '')),
                trim((string) ($payload['head'] ?? '')),
            );
        }

        $commits = array_slice($commits, 0, self::MAX_COMMITS_PER_PUSH);

        $rows = [];
        $seenMessages = [];

        foreach ($commits as $index => $commit) {
            $message = trim((string) ($commit['message'] ?? ''));
            $signature = mb_strtolower($message);

            // PRD §4.3: empty or duplicated messages inside one push are ignored.
            if ($message === '' || isset($seenMessages[$signature])) {
                continue;
            }
            $seenMessages[$signature] = true;

            $sha = (string) ($commit['sha'] ?? '');

            // The same commit must never score twice, whether it resurfaces
            // in another push or in the looser new-ref listing above.
            if ($sha !== '' && $this->shaAlreadyCounted($user, $repo, $sha)) {
                continue;
            }

            if ($sha !== '') {
                $this->seenShas[$repo.':'.mb_strtolower($sha)] = true;
            }

            $rows[] = [
                'github_event_id' => $sha !== '' ? "{$eventId}:{$sha}" : "{$eventId}:{$index}",
                'type' => ActivityType::Commit,
                'repo_full_name' => $repo,
                'occurred_at' => $occurredAt,
                'metadata' => [
                    'sha' => $sha,
                    'message' => Str::limit($message, 200, ''),
                    'author' => (string) ($commit['author']['name'] ?? ''),
                    'url' => (string) ($commit['url'] ?? ''),
                ],
            ];
        }

        return $rows;
    }

    private function shaAlreadyCounted(User $user, string $repo, string $sha): bool
    {
        $key = $repo.':'.mb_strtolower($sha);

        if (isset($this->seenShas[$key])) {
            return true;
        }

        if (! isset($this->recordedShas[$repo])) {
            $recorded = [];

            ActivityEvent::query()
                ->where('user_id', $user->id)
                ->where('repo_full_name', $repo)
                ->where('type', ActivityType::Commit)
                ->get(['metadata'])
                ->each(function (ActivityEvent $event) use (&$recorded): void {
                    $sha = mb_strtolower((string) ($event->metadata['sha'] ?? ''));

                    if ($sha !== '') {
                        $recorded[$sha] = true;
                    }
                });

            $this->recordedShas[$repo] = $recorded;
        }

        return isset($this->recordedShas[$repo][mb_strtolower($sha)]);
    }

    /**
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function pullRequestRows(string $eventId, string $repo, CarbonImmutable $occurredAt, array $payload): array
    {
        $action = (string) ($payload['action'] ?? '');
        $pullRequest = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

        $type = match (true) {
            $action === 'opened' => ActivityType::PrOpened,
            $action === 'closed' && (bool) ($pullRequest['merged'] ?? false) => ActivityType::PrMerged,
            default => null,
        };

        if ($type === null) {
            return [];
        }

        return [[
            'github_event_id' => "{$eventId}:{$type->value}",
            'type' => $type,
            'repo_full_name' => $repo,
            'occurred_at' => $occurredAt,
            'metadata' => [
                'number' => (int) ($pullRequest['number'] ?? 0),
                'title' => Str::limit((string) ($pullRequest['title'] ?? ''), 200, ''),
                'url' => (string) ($pullRequest['html_url'] ?? ''),
            ],
        ]];
    }

    /**
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function reviewRows(string $eventId, string $repo, CarbonImmutable $occurredAt, array $payload): array
    {
        $review = is_array($payload['review'] ?? null) ? $payload['review'] : [];
        $pullRequest = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

        if (($review['id'] ?? null) === null && ($review['state'] ?? null) === null) {
            return [];
        }

        return [[
            'github_event_id' => "{$eventId}:review",
            'type' => ActivityType::PrReview,
            'repo_full_name' => $repo,
            'occurred_at' => $occurredAt,
            'metadata' => [
                'state' => (string) ($review['state'] ?? 'commented'),
                'number' => (int) ($pullRequest['number'] ?? 0),
                'title' => Str::limit((string) ($pullRequest['title'] ?? ''), 200, ''),
            ],
        ]];
    }

    /**
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function issueRows(string $eventId, string $repo, CarbonImmutable $occurredAt, array $payload): array
    {
        if (($payload['action'] ?? null) !== 'closed') {
            return [];
        }

        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];

        return [[
            'github_event_id' => "{$eventId}:issue",
            'type' => ActivityType::IssueClosed,
            'repo_full_name' => $repo,
            'occurred_at' => $occurredAt,
            'metadata' => [
                'number' => (int) ($issue['number'] ?? 0),
                'title' => Str::limit((string) ($issue['title'] ?? ''), 200, ''),
            ],
        ]];
    }

    /**
     * PRD §4.3: ignore events in forks the user owns.
     *
     * @param  list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>  $rows
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function dropOwnedForks(User $user, array $rows): array
    {
        $verdicts = [];

        return array_values(array_filter($rows, function (array $row) use ($user, &$verdicts) {
            $repo = $row['repo_full_name'];

            if (! isset($verdicts[$repo])) {
                $verdicts[$repo] = $this->github->isForkOwnedByUser($user, $repo);
            }

            return ! $verdicts[$repo];
        }));
    }

    /**
     * PRD §4.2: the first event in a repo the user has never earned XP in also
     * grants a one-off `new_repo` bonus.
     *
     * @param  list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>  $rows
     * @return list<array{github_event_id: string, type: ActivityType, repo_full_name: string, occurred_at: CarbonImmutable, metadata: array<string, mixed>}>
     */
    private function addNewRepoBonuses(User $user, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $repos = array_values(array_unique(array_column($rows, 'repo_full_name')));

        $known = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('repo_full_name', $repos)
            ->pluck('repo_full_name')
            ->all();

        $unknown = array_diff($repos, $known);
        if ($unknown === []) {
            return $rows;
        }

        $bonus = [];

        foreach (array_flip($unknown) as $repo => $_) {
            $first = null;

            foreach ($rows as $row) {
                if ($row['repo_full_name'] === $repo && $first === null) {
                    $first = $row;
                }
            }

            if ($first === null) {
                continue;
            }

            $bonus[] = [
                'github_event_id' => $first['github_event_id'].':new_repo',
                'type' => ActivityType::NewRepo,
                'repo_full_name' => $repo,
                'occurred_at' => $first['occurred_at'],
                'metadata' => ['repo' => $repo],
            ];
        }

        return array_merge($bonus, $rows);
    }

    private function occurredAt(array $event): ?CarbonImmutable
    {
        $raw = $event['created_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function repo(array $event): ?string
    {
        $repo = $event['repo']['name'] ?? null;

        return is_string($repo) && str_contains($repo, '/') ? $repo : null;
    }
}
