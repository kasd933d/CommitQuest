# CommitQuest API

Laravel 12 JSON API for CommitQuest: GitHub OAuth, activity ingestion, XP ledger,
streaks, quests, badges, guilds and leaderboards.

Full documentation lives in the repository root:

- [`../../README.md`](../../README.md) — run instructions and changelog
- [`../../CommitQuest_PRD.md`](../../CommitQuest_PRD.md) — product requirements
- [`../../docs/decisions.md`](../../docs/decisions.md) — engineering decisions

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve     # http://localhost:8000/api/v1
composer test         # test suite
vendor/bin/pint       # code style
```
