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
