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

### Repository
Private repo `kasd933d/CommitQuest`, default branch `main`, conventional commits.

### Test framework: PHPUnit (not Pest)
The Laravel 12 skeleton ships PHPUnit 11 and the suite runs green as-is; Pest was
not added in M0 to keep the bootstrap small. Revisit if test ergonomics become a
bottleneck in M2/M3.
