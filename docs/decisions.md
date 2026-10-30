# Development decisions log (PRD section 15.1)

Every non-obvious choice made while building CommitQuest is recorded here.

## 2026-10-25 — Milestone 0

### Laravel 11 → Laravel 12
The PRD specifies Laravel 11 on PHP 8.3. Composer 2.10 refuses to install
`laravel/framework ^11.0` because every 11.x release (up to v11.57.0) is flagged
by active security advisories on Packagist. Installing a known-vulnerable
framework version just to match the document is the wrong trade, so the API runs
**Laravel 12 (v12.70.x)** on **PHP 8.3**, which satisfies the PRD's PHP
constraint and its architecture requirements (Sanctum, Socialite, queues,
scheduler, policies) unchanged.

### Local runtime: native PHP + SQLite (no Docker engine on the dev machine)
Docker Desktop is installed but its engine cannot start: WSL reports
`Windows Subsystem for Linux has no installed distributions`, and fixing that
requires a reboot. Local verification therefore runs on:

- PHP 8.3.36 (NTS, Win64) with `pdo_sqlite`, `sqlite3`, `mbstring`, `curl`,
  `dom`, `xml`, `zip`, `intl`, `gd`, `bcmath`
- Composer 2.10
- SQLite (`:memory:`) for the test suite, `database/database.sqlite` for the
  local dev server

`docker-compose.yml` (api, web, queue, scheduler, mysql) and both Dockerfiles
are still authored and shipped for CI/CD and deployment, but could not be booted
on this machine. MySQL 8.4 remains the production target; migrations are written
to be valid on both MySQL and SQLite so the same suite runs in CI against either.

## 2026-10-25 — Milestone 1 (Auth)

### The users table drops `email`/`password`
PRD §8 defines `users` around a GitHub identity (`github_id`, `username`,
`avatar_url`, `github_token`), not credentials. The initial migration was edited
rather than layered on, because nothing has shipped yet - the PRD's schema is
the source of truth. `password_reset_tokens` and `sessions` stay in the
migration because `SESSION_DRIVER=database` is still configured for web routes.

### OAuth state is validated in a cache, not the session
Socialite runs `stateless()` because the browser is talking to Next.js, not to
Laravel: `/auth/login` (Next) → GitHub → `/auth/callback` (Next) → Laravel
`/auth/github/callback`. Laravel therefore generates a single-use 40-character
state, stores it in the cache for 10 minutes and returns the authorize URL with
`&state=...`; the callback `Cache::pull()`s it, so a state can only be used once
and a forged code is rejected with 422.

### The token never reaches browser JavaScript
`POST /auth/callback` (Next route handler) stores the Sanctum token in an
httpOnly, SameSite=Lax cookie named `cq_token`. All browser traffic goes to
`/api/v1/*` on the Next origin, where a catch-all route handler reads the cookie
server-side and forwards `Authorization: Bearer ...` to Laravel. `GET /me`
replies with the resource only; `github_token` and `events_etag` are in the
model's `$hidden` and covered by a test that asserts they never appear in JSON.

### Testing: guards are flushed between requests
Laravel's `RequestGuard` caches the resolved user for the lifetime of the app
instance (production = one request; a test = many). Without a reset, a test
that logs out and then calls `/me` would still see the cached user. The base
`Tests\TestCase` flushes the guards after every `call()` and re-applies anything
set through `actingAs()` - this is what makes idempotency tests (M2) possible.

### Rate limits
`throttle:api` = 60/min keyed by the Sanctum user id (IP when anonymous),
`throttle:public` = 30/min keyed by IP, both returning the PRD's JSON error
envelope with 429. The `POST /auth/*` endpoints are on `throttle:public`.



### Repository
Private repo `kasd933d/CommitQuest`, default branch `main`, conventional commits.

### Test framework: PHPUnit (not Pest)
The Laravel 12 skeleton ships PHPUnit 11 and the suite runs green as-is; Pest was
not added in M0 to keep the bootstrap small. Revisit if test ergonomics become a
bottleneck in M2/M3.

---

## 2026-10-25 — Milestone 2 (Ingestion & XP)

### Idempotency is enforced by the database, not by code
Two unique indexes do all the work: `activity_events.github_event_id` (the raw
GitHub id, suffixed `:{sha}` when one PushEvent yields several rows) and
`xp_ledger.(user_id, source_type, source_id)`. A second sync simply fails the
first insert and is skipped by an `exists()` check; a second award attempt fails
the ledger insert. `XpService::award()` also re-reads the event with
`lockForUpdate()` inside a transaction and checks `awarded_at`, so concurrent
jobs cannot race. Tests: syncing twice keeps the event count and `total_xp`
byte-identical.

### Award state lives on the event, XP truth lives in the ledger
`activity_events` carries `xp_awarded`, `awarded_at` and `skipped_reason`
(`daily_repo_cap` / `daily_xp_cap` / `missing_user`), so the feed can show both
rewards and *why* something was not rewarded. `xp_ledger` is append-only and is
the only input to `users.total_xp`, which `xp:rebuild` recomputes with the same
`Levels::fromXp()` formula the API uses. Quest and badge XP will be written with
`source_type = quest|badge`, which is exactly why the daily cap is measured on
activity events instead of on the ledger sum (quest XP must not consume the
300 XP activity allowance).

### Day boundaries are computed in the user's timezone
All timestamps are stored in UTC. For both caps the event's instant is converted
to `user.timezone`, truncated to the start of that local day and converted back
to UTC to form a half-open `[start, end)` window. A test proves the difference:
ten commits at 20:00 UTC fill the cap for the *next* local day in
`Asia/Karachi` (UTC+5), and a commit 15 hours earlier still fits.

### Sync is a three-layer pipeline
`GithubService` (ETag conditional GET, 304/401/403/429 interpretation, cached
fork check, `MIN_SAFE_REMAINING = 50` backoff) → `ActivityNormalizer` (pure
mapping: 90-day cutoff, 20 commits/push, empty & duplicate message filtering,
PR/issue/review mapping, owned-fork filtering, `new_repo` bonus) → `SyncService`
(persists rows, records a `sync_runs` row, dispatches `ProcessActivityEvent`).
HTTP is only ever touched through `GithubService`, so the whole integration is
replaceable with `Http::fake()` in tests.

### Rate-limit responses are data, not exceptions
A GitHub 403/429 records `sync_runs.status = rate_limited` plus the remaining
quota (cached for an hour) and the job returns normally; the next scheduled run
backs off *before* spending a request while the quota is below the safe
threshold. A 401 sets `users.sync_status = needs_reauth` so the dashboard can ask
the user to sign in again. Nothing throws out of the sync path, so the queue
never marks the job as failed for something that is expected.

### Tests never touch the development database
`phpunit.xml` pins `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`. Before that,
the suite ran on `database/database.sqlite` — the same file as the dev server —
so `RefreshDatabase` migrated it fresh for every test and silently deleted the
local account, its tokens and its sync history. CI exports the same two
variables, so the runner needs no `.env` either.

### Manual sync throttle = one cache lock
`POST /me/sync` uses `Cache::add('sync:lock:{id}', 1, 300)`: the first call wins
and queues the job, the rest get 429 with a `Retry-After` header. No extra table,
and the lock doubles as the `sync.state` flag returned by `GET /me/activity`.

### Client and server API clients are separate modules
`lib/api.ts` starts with `import "server-only"`, so a Client Component cannot
import it (Next.js fails the build). `apiServer()` stays in `lib/api.ts`, the
browser helper `apiFetch()` moved to `lib/api-fetch.ts`, and the shared response
handling lives there too. This keeps the token strictly server-side while
letting `SyncButton` call `POST /me/sync` through the proxy.

### PushEvent payloads no longer carry commits — resolved via Compare API
Verified live against the real Events API: a `PushEvent` now ships only
`{repository_id, push_id, ref, head, before}` (no `commits` array), so the PRD's
"one row per commit in payload" would silently drop every push. `GithubService::pushCommits()`
now resolves the range: `GET /repos/{repo}/compare/{before}...{head}` returns
exactly the pushed commits; an all-zero `before` (brand-new ref) has nothing to
compare (404), so it falls back to `GET /repos/{repo}/commits?sha={head}&per_page=20`,
and a failed compare falls back the same way. Both endpoints nest
`message`/`author` under `commit` (the old payload had them top-level), so
`pushCommits()` flattens them into the payload shape first — without that,
every resolved commit looked message-less and the empty-message rule silently
dropped all of them. The looser listing is made safe by
sha-level dedupe in `ActivityNormalizer`: a commit sha already recorded for that
user + repo (or already emitted earlier in the same normalize pass) is never
counted again, so branch copies, re-pushes and the fallback listing cannot
double-award. Legacy payloads that still contain `commits` are handled without
any extra HTTP call. Tests cover all three paths plus cross-push dedupe.

### M3: quest assignment runs every 15 minutes, not at each user's midnight
PRD says "each day at 00:00 in the user's timezone assign 3 daily quests".
Per-timezone cron is operationally heavy (one job per tz), so instead
quests:assign-due runs every 15 minutes for all users active in the last
168h and assigns whatever their *current local day/week* is missing.
Assignment is idempotent (unique (user_id, quest_template_id, period_start)
index + insertOrIgnore), so extra ticks are free. GET /me/quests also
assigns on demand, so a fresh account never sees an empty board.

### M3: quest progress is recomputed from activity events, never incremented
QuestService::progress() counts ctivity_events inside the quest's UTC
window (derived from the user's timezone) on every ingested event instead of
incrementing a counter. Recompute-over-increment cannot drift, survives event
reprocessing, and counts events even when the daily XP cap skipped them
(quests measure activity, not paid XP). Completion pays through the ledger's
unique (user_id, source_type, source_id) index, so a double pay is
impossible; quest XP is exempt from the activity caps per the PRD.

### M3: reference data ships as a migration, not a seeder
Quest templates (13) and badges (12) are upserted by a migration so every
environment -- local, RefreshDatabase tests, production -- has them without
an extra operational step (no `db:seed` to remember on deploy). Upserts keep
it re-runnable when templates are tuned.

### M3: badges grant no XP
The PRD's badge table has no reward column and section 4.6 lists no XP, so
badges are pure achievements (notification + display). If rewards are wanted
later, they must go through xp_ledger with source_type = badge to stay
rebuildable by `xp:rebuild`.

### M3: heatmap returns whole Monday-start weeks
The 12-week heatmap returns exactly 12x7 cells aligned to Monday-start weeks
(in the user's timezone) instead of a rolling 84-day window, so a
grid-flow-col render gives one tidy column per week with stable weekday
rows. Days after today are included as zero-count cells.

### M4: private profiles 404 everywhere (never confirm existence)
GET /users/{username} returns 404 for both unknown and private users, and
the Next.js profile page maps that to 
otFound(). Returning 403 would
confirm the account exists, which leaks signups; 404 keeps the surface
uniform. The all-time leaderboard also filters is_public so private users
never appear there either.

### M4: weekly windows are UTC Monday 00:00 (per the PRD), not user-local
PRD �4.7 defines the weekly leaderboard window as "Monday 00:00 UTC to
Sunday 23:59 UTC", so guild/global weekly boards read xp_ledger.created_at
against a UTC week start. Streaks and quests stay user-timezone-local per
�4.4 -- the two rules intentionally differ because leaderboards must be
comparable across all participants.

### M4: guild limits are enforced in the service under a row lock
GuildService::create/join wrap the membership count + insert in a
transaction with lockForUpdate() on the guild row, so the max-3-guilds and
max-50-members rules cannot be raced past with concurrent joins. The
guild_members (guild_id, user_id) unique index is the second line of
defense (insertOrIgnore makes double-joins no-ops).

### M4: the invite code is only serialized to members
GuildResource gates invite_code behind is_member, so private guilds
are joinable only by people the code was shared with; non-members reading
the guild index/detail never see it. Owners regenerate the code (invalidating
the old one) via a policy-protected endpoint.

### M4: README badge route is a Next.js route, not an API endpoint
The SVG is rendered by pps/web/src/app/api/badge/[username]/route.ts
(server-side, calling the API internally with piServer) so GitHub's
camo proxy fetches a same-origin URL with CDN-friendly s-maxage=600
headers. Unknown/private usernames get a neutral "CommitQuest � @user"
badge so the route cannot be used to probe for accounts; usernames are
regex-validated and the label is XML-escaped to keep the SVG injection-free.
