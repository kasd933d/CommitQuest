# CommitQuest — Product Requirements Document

> Turn your real GitHub activity into an RPG. Commits, pull requests and code reviews earn XP, level you up, complete quests, unlock badges and push your guild up the weekly leaderboard.

**Version:** 1.0
**Owner:** Abdullah
**Builders:** Abdullah + AI coding agent
**Stack:** Next.js (TypeScript) + Tailwind CSS · Laravel 11 (PHP 8.3) REST API · MySQL 8 · GitHub OAuth + GitHub REST API

---

## 1. Why this project

### 1.1 Purpose
Developers want to build consistent coding habits, but streak trackers are boring and GitHub's contribution graph says nothing about *what* you did. CommitQuest reads your real GitHub activity and makes it a game: you gain XP, level up, finish daily/weekly quests, keep streaks alive, join guilds and show off a shareable profile card and README badge.

### 1.2 Why it works as a portfolio project
It covers the full-stack skills hiring managers look for, in one coherent product:

| Skill | Where it shows up |
|---|---|
| Third-party API integration | GitHub OAuth, REST Events API, ETag/conditional requests, rate-limit handling |
| Backend architecture | Laravel services, queued jobs, scheduler, idempotent ingestion |
| Database design | Relational schema, ledger pattern, indexes, leaderboards |
| Auth & security | OAuth, Sanctum tokens, encrypted tokens, rate limiting, authorization policies |
| Frontend | Next.js App Router, TypeScript, server and client components, dynamic OG images |
| Product thinking | Game design, anti-abuse rules, shareable growth loop |
| Engineering practice | Tests, CI, Docker, env config, API docs |

### 1.3 Goals
1. Ship a working, deployed, public product with real GitHub login.
2. Make the XP system fair and hard to farm.
3. Make every profile shareable (profile page, OG image, README badge).
4. Keep the codebase clean enough to show in interviews.

### 1.4 Non-goals (v1)
- No private repository data (public activity only).
- No payments, subscriptions or ads.
- No native mobile app (the web app must be responsive).
- No other providers (GitLab, Bitbucket). Design for it, do not build it.
- No real-time websockets. Use polling.

---

## 2. Target users

| Persona | Description | Need |
|---|---|---|
| **Learner Lena** | Student or junior dev learning to code | Motivation and consistency |
| **Builder Bilal** | Working dev with side projects | Fun accountability, a nice profile to share |
| **Guild Leader Gul** | Runs a study group or bootcamp cohort | See who is active, run friendly competition |

---

## 3. Core concepts

- **Activity event:** a normalized record of something done on GitHub (push, PR opened, PR merged, review, issue closed).
- **XP:** points earned from activity events, recorded in an append-only ledger.
- **Level:** derived from total XP.
- **Streak:** consecutive days with at least one qualifying event.
- **Quest:** a short goal (daily or weekly) with an XP reward.
- **Badge:** a permanent achievement.
- **Guild:** a group of users with a weekly leaderboard.

---

## 4. Features

Priority: **P0** = must ship (MVP), **P1** = should ship, **P2** = stretch.

### 4.1 Authentication (P0)
- Sign in with GitHub (OAuth 2.0). Scope: `read:user` only.
- On first login create the user, store the GitHub id, username, avatar, and an encrypted access token.
- Logout invalidates the API token.
- Account deletion removes all user data (hard delete) within one request.

**Acceptance criteria**
- A new user can go from landing page to dashboard in under 3 clicks.
- Tokens are never exposed to the browser JavaScript (httpOnly cookie on the Next.js side).
- Revoking the OAuth app on GitHub makes the next sync fail gracefully and flags the account as `needs_reauth`.

### 4.2 GitHub activity sync (P0)
- A scheduled job polls each active user's public events every 15 minutes using the GitHub Events API with `ETag` / `If-None-Match` to save rate limit.
- Manual "Sync now" button (rate limited to once per 5 minutes per user).
- Events are normalized into `activity_events`. Duplicates are impossible (unique on `github_event_id`).
- Initial backfill on signup covers up to the last 30 days of what the API returns.

**Qualifying events**

| GitHub event | Internal type |
|---|---|
| `PushEvent` | `commit` (one per commit in payload, max 20 per push) |
| `PullRequestEvent` action `opened` | `pr_opened` |
| `PullRequestEvent` action `closed` + merged | `pr_merged` |
| `PullRequestReviewEvent` | `pr_review` |
| `IssuesEvent` action `closed` | `issue_closed` |
| First event in a repo the user has never earned XP in | bonus `new_repo` |

**Acceptance criteria**
- Syncing twice in a row never double-counts XP.
- A GitHub 403/429 rate-limit response causes the job to back off and retry later, not crash.
- Sync status (last synced, last error) is visible on the dashboard.

### 4.3 XP, levels and anti-abuse (P0)

**XP table**

| Type | XP |
|---|---|
| commit | 5 |
| pr_opened | 20 |
| pr_merged | 40 |
| pr_review | 25 |
| issue_closed | 15 |
| new_repo bonus | 30 |

**Anti-farming rules**
- Max **10 commits** counted per repo per day.
- Max **300 XP** from activity per day (quest and badge rewards are not capped).
- Commits with an empty or duplicate message within the same push are ignored.
- Events from repos the user owns that are forks with zero own commits are ignored.
- Activity older than 90 days is never counted.

**Levels**
- `level = floor(sqrt(total_xp / 100)) + 1`
- XP needed for level `L` = `100 * (L - 1)^2`
- Show a progress bar to the next level.

**Ledger pattern**
- Every XP change is a row in `xp_ledger` (positive only in v1). `users.total_xp` is a cached sum that can always be rebuilt from the ledger.

**Acceptance criteria**
- Rebuild command `php artisan xp:rebuild {user}` recomputes total XP and level identically.
- Unit tests cover every XP rule and every cap.

### 4.4 Streaks (P0)
- A day counts if the user has at least one qualifying event on that date in their chosen timezone.
- Track `current_streak`, `longest_streak`, `last_active_date`.
- **Streak freeze:** user earns 1 freeze per 7-day streak (max 2 banked). A freeze automatically covers one missed day.
- Dashboard shows a 12-week heatmap of active days.

### 4.5 Quests (P0)
- Each day at 00:00 in the user's timezone assign **3 daily quests** picked from templates. Each week assign **1 weekly "boss" quest**.
- Progress updates automatically when activity is ingested.
- On completion: award XP, create a notification, mark completed. Rewards are claimed automatically (no claim button).

**Example quest templates (seed at least 12)**

| Key | Type | Metric | Target | Reward |
|---|---|---|---|---|
| `daily_commit_3` | daily | commits | 3 | 30 XP |
| `daily_review_1` | daily | pr_review | 1 | 40 XP |
| `daily_pr_open_1` | daily | pr_opened | 1 | 40 XP |
| `daily_two_repos` | daily | distinct repos | 2 | 35 XP |
| `weekly_merge_3` | weekly | pr_merged | 3 | 200 XP |
| `weekly_5_days` | weekly | active days | 5 | 250 XP |

### 4.6 Badges (P0)
Permanent achievements checked after every ingestion. Seed at least 12:

| Badge | Rule |
|---|---|
| First Blood | First qualifying event |
| Week Warrior | 7-day streak |
| Monthly Monk | 30-day streak |
| Merge Master | 25 merged PRs |
| Code Reviewer | 20 reviews |
| Night Owl | 10 commits between 00:00 and 04:00 (user timezone) |
| Early Bird | 10 commits between 04:00 and 07:00 |
| Explorer | Activity in 10 different repos |
| Level 10 | Reach level 10 |
| Quest Slayer | Complete 25 quests |
| Boss Slayer | Complete 5 weekly quests |
| Guild Founder | Create a guild |

### 4.7 Guilds and leaderboards (P1)
- Create a guild (name, slug, description, public/private).
- Join by invite code (private) or directly (public). Max **50 members**.
- Roles: `owner`, `member`. Owner can remove members and regenerate the invite code.
- Weekly leaderboard (Monday 00:00 UTC to Sunday 23:59 UTC) ranked by weekly XP.
- Global leaderboard (top 100 by weekly XP, top 100 by total XP).
- A user can belong to a maximum of 3 guilds.

### 4.8 Public profile and sharing (P0 for profile, P1 for the rest)
- Public page at `/u/[username]` showing level, XP, streak, badges, heatmap, recent quests. Users can toggle profile visibility.
- **Dynamic OG image** for `/u/[username]` (Next.js `ImageResponse`): avatar, level, XP, streak.
- **README badge:** `GET /api/badge/{username}.svg` returns an SVG ("CommitQuest · Lv 12 · 🔥 9 day streak"). Cached for 10 minutes. Users get a copy-paste Markdown snippet in settings.

### 4.9 Notifications (P1)
- In-app notification bell for: level up, quest complete, badge unlocked, streak at risk (no activity yet today after 6 PM local), guild invite.
- Client polls `/api/notifications` every 60 seconds. Mark as read individually or all.

### 4.10 Settings (P0)
- Timezone (required, defaults from browser).
- Profile visibility toggle.
- Manual sync button and sync status.
- Delete account.

### 4.11 Stretch ideas (P2)
- Discord webhook: post level-ups and weekly guild winners to a channel.
- Weekly email digest.
- Seasonal events (double XP weekends).
- SSE for live notifications.
- Contribution analytics charts (languages, busiest hours).

---

## 5. User flows

**Onboarding:** Landing → "Sign in with GitHub" → GitHub consent → callback → pick timezone → backfill runs → dashboard shows first XP and quests.

**Daily loop:** Open dashboard → see today's quests and streak → code on GitHub → return later → sync (automatic) → XP, quest progress and notifications updated.

**Sharing:** Settings → copy README badge snippet or profile link → paste on GitHub README / LinkedIn → OG preview card renders.

**Guild:** Create guild → copy invite code → friends join → guild page shows weekly leaderboard.

---

## 6. Pages (Next.js App Router)

| Route | Purpose | Auth |
|---|---|---|
| `/` | Landing page, live global stats, CTA | Public |
| `/login` | GitHub sign-in | Public |
| `/auth/callback` | Completes OAuth, sets cookie | Public |
| `/dashboard` | XP bar, level, streak, heatmap, quests, recent activity | Required |
| `/quests` | Daily/weekly quests and history | Required |
| `/badges` | All badges, locked and unlocked | Required |
| `/guilds` | My guilds, discover public guilds | Required |
| `/guilds/[slug]` | Guild leaderboard and members | Required (public guilds readable) |
| `/leaderboard` | Global weekly and all-time boards | Public |
| `/u/[username]` | Public profile | Public (if visible) |
| `/settings` | Timezone, visibility, sync, badge snippet, delete account | Required |

**UI requirements**
- Responsive, mobile first (Tailwind).
- Dark theme by default with a light option.
- Pixel/retro RPG-inspired visual style, readable and accessible (WCAG AA contrast).
- Loading skeletons and clear empty/error states on every data view.
- Level-up and badge-unlock animations (CSS only, respect `prefers-reduced-motion`).

---

## 7. Architecture

```
┌────────────────────┐        ┌──────────────────────────┐        ┌───────────┐
│ Next.js (Vercel)   │ HTTPS  │ Laravel API (Docker)     │  SQL   │ MySQL 8   │
│ App Router, TS     │───────▶│ Sanctum, Socialite       │───────▶│           │
│ httpOnly cookie    │        │ Queue worker + Scheduler │        └───────────┘
└────────────────────┘        └───────────┬──────────────┘
                                          │ HTTPS
                                          ▼
                                   GitHub REST API
```

- **Frontend:** Next.js 14+ App Router, TypeScript, Tailwind CSS. Server components for public pages (SEO, OG). Route handlers act as a thin proxy that reads the httpOnly cookie and forwards the token to Laravel.
- **Backend:** Laravel 11 JSON API. OAuth through Laravel Socialite. API auth via Sanctum personal access tokens. Business logic lives in service classes, not controllers.
- **Queue:** database queue driver (no Redis required). Jobs: `SyncGithubEvents`, `ProcessActivityEvent`, `AssignDailyQuests`, `AssignWeeklyQuest`, `EvaluateBadges`, `RecomputeLeaderboards`.
- **Scheduler (cron every minute → `schedule:run`):**
  - every 15 min: dispatch sync for active users (staggered to avoid bursts)
  - hourly: assign quests for users whose local midnight just passed
  - every 10 min: refresh leaderboard cache
  - daily: streak maintenance and streak-at-risk notifications
- **Cache:** Laravel cache (database or file driver) for badge SVGs, leaderboards, profile aggregates.

### 7.1 Suggested repository layout (monorepo)

```
commitquest/
├── apps/
│   ├── web/                # Next.js app
│   │   ├── src/app/...
│   │   ├── src/components/
│   │   ├── src/lib/api.ts  # typed API client
│   │   └── src/types/
│   └── api/                # Laravel app
│       ├── app/Services/   # XpService, StreakService, QuestService, BadgeService, GithubService
│       ├── app/Jobs/
│       ├── app/Http/Controllers/Api/
│       ├── app/Http/Resources/
│       ├── app/Policies/
│       └── tests/
├── docker-compose.yml
├── .github/workflows/ci.yml
├── docs/
└── README.md
```

---

## 8. Data model (MySQL)

All tables use `id` bigint unsigned PK, `created_at`, `updated_at` unless stated.

**users**
`github_id` (unique), `username` (unique), `display_name`, `avatar_url`, `github_token` (encrypted), `timezone` (default `UTC`), `is_public` (default true), `total_xp` (default 0), `level` (default 1), `current_streak`, `longest_streak`, `last_active_date` (nullable date), `streak_freezes` (default 0), `events_etag` (nullable), `last_synced_at`, `sync_status` (`ok`|`error`|`needs_reauth`), `sync_error` (nullable)

**activity_events**
`user_id` FK, `github_event_id` (unique), `type` (enum), `repo_full_name`, `occurred_at`, `metadata` JSON, `xp_awarded` (default 0)
Indexes: `(user_id, occurred_at)`, `(user_id, repo_full_name, occurred_at)`

**xp_ledger**
`user_id` FK, `source_type` (`activity`|`quest`|`badge`), `source_id`, `amount`, `reason`
Index: `(user_id, created_at)`

**quest_templates**
`key` (unique), `title`, `description`, `period` (`daily`|`weekly`), `metric`, `target`, `xp_reward`, `is_active`

**user_quests**
`user_id` FK, `quest_template_id` FK, `period_start` (date), `period_end` (date), `progress`, `target`, `status` (`active`|`completed`|`expired`), `completed_at`
Unique: `(user_id, quest_template_id, period_start)`

**badges**
`key` (unique), `name`, `description`, `icon`, `rarity`

**user_badges**
`user_id` FK, `badge_id` FK, `unlocked_at`. Unique: `(user_id, badge_id)`

**guilds**
`name`, `slug` (unique), `description`, `owner_id` FK, `is_public`, `invite_code` (unique)

**guild_members**
`guild_id` FK, `user_id` FK, `role` (`owner`|`member`), `joined_at`. Unique: `(guild_id, user_id)`

**notifications**
`user_id` FK, `type`, `title`, `body`, `data` JSON, `read_at` (nullable)
Index: `(user_id, read_at, created_at)`

**sync_runs**
`user_id` FK, `started_at`, `finished_at`, `events_fetched`, `events_new`, `status`, `error` (nullable), `rate_limit_remaining`

---

## 9. REST API

Base path `/api/v1`. JSON only. Standard envelope for errors: `{ "message": string, "errors"?: object }`. Pagination with `?page=` and `?per_page=` (max 50).

### Auth
| Method | Path | Description |
|---|---|---|
| GET | `/auth/github/redirect` | Returns the GitHub authorize URL |
| GET | `/auth/github/callback` | Exchanges code, creates user, returns API token |
| POST | `/auth/logout` | Revokes current token |

### Me
| Method | Path | Description |
|---|---|---|
| GET | `/me` | Profile, XP, level, next level threshold, streak |
| PATCH | `/me` | Update timezone, `is_public` |
| DELETE | `/me` | Delete account and all data |
| POST | `/me/sync` | Trigger manual sync (throttled) |
| GET | `/me/activity` | Paginated activity feed |
| GET | `/me/heatmap` | Last 12 weeks, count per day |
| GET | `/me/quests` | Current and recent quests |
| GET | `/me/badges` | All badges with unlocked state |
| GET | `/me/notifications` | Paginated, unread count |
| POST | `/me/notifications/read` | Mark all or listed ids read |

### Public
| Method | Path | Description |
|---|---|---|
| GET | `/users/{username}` | Public profile (404 if private) |
| GET | `/leaderboard?scope=weekly\|all` | Top 100 |
| GET | `/badge/{username}.svg` | README badge (cached) |
| GET | `/stats` | Global counters for landing page |

### Guilds
| Method | Path | Description |
|---|---|---|
| GET | `/guilds` | Public guilds and my guilds |
| POST | `/guilds` | Create |
| GET | `/guilds/{slug}` | Detail and weekly leaderboard |
| POST | `/guilds/{slug}/join` | Join (body: `invite_code` if private) |
| DELETE | `/guilds/{slug}/leave` | Leave |
| DELETE | `/guilds/{slug}/members/{userId}` | Owner removes member |
| POST | `/guilds/{slug}/invite/regenerate` | Owner regenerates code |

**Rate limits:** 60 req/min per authenticated user, 30 req/min per IP for public endpoints, `/me/sync` once per 5 minutes.

---

## 10. GitHub integration details

- **OAuth app** (not GitHub App) for simplicity. Callback URL set per environment.
- **Endpoint used:** `GET /users/{username}/events/public` with `If-None-Match` and the stored ETag. A `304` response costs no rate limit.
- **Authenticated requests** use the user's token (5,000 req/hour per user).
- **Known API limits to design around:** the Events API returns at most 300 events from the last 90 days and can lag by a few minutes. Document this in the UI ("activity may take up to 15 minutes to show up").
- Handle `Retry-After`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` headers. If remaining is below 50, skip non-essential syncs until reset.
- Wrap all GitHub calls in a single `GithubService` class so it can be mocked in tests (use `Http::fake()`).

---

## 11. Non-functional requirements

| Area | Requirement |
|---|---|
| Performance | p95 API response under 300 ms for cached reads; dashboard first load under 2.5 s on 4G |
| Reliability | Jobs are idempotent and retry with exponential backoff (max 5 tries) |
| Security | Tokens encrypted at rest; CSRF-safe flows; strict CORS to the web origin only; validated input everywhere (Form Requests); authorization via Laravel Policies; no secrets in the repo |
| Privacy | Only public GitHub data is read; users can hide profile and delete account; no tracking scripts |
| Accessibility | Keyboard navigable, semantic HTML, WCAG AA contrast, reduced-motion support |
| SEO | Server-rendered public profiles with proper meta and OG tags |
| Observability | Structured logs, failed jobs table, `/up` health endpoint |
| Scalability | Designed for about 10k users on a single small instance; staggered sync scheduling |

---

## 12. Constraints and assumptions

- Free-tier friendly hosting: Vercel (web), a small container host such as Railway/Render/Fly.io (API + worker + scheduler), managed or containerized MySQL.
- No Redis in v1; database queue and cache only.
- GitHub rate limits and Events API limits are hard external constraints (see section 10).
- Solo developer plus AI agent: scope must stay shippable in roughly 4 to 6 weeks of part-time work.
- Business logic must be deterministic and unit-tested; no AI features required.
- Timezone handling must be explicit: store all timestamps in UTC, convert to the user's timezone only for day-boundary logic.

---

## 13. Testing strategy

**Backend (Pest or PHPUnit)**
- Unit: XP calculation, caps, level formula, streak logic (including freezes and timezone edges), quest progress, badge rules.
- Feature: every endpoint (auth required, validation, policies), sync job with `Http::fake()`, idempotency (run twice, same result).

**Frontend**
- Component tests for XP bar, quest card, heatmap (Vitest + Testing Library).
- One Playwright end-to-end flow with a mocked API: login → dashboard → sync → quest complete.

**CI (GitHub Actions)**
- On every PR: lint (ESLint, Pint), type check (`tsc --noEmit`), backend tests, frontend tests, build.

**Definition of done for any task:** code merged, tests added/passing, lint clean, no console errors, docs updated if behavior changed.

---

## 14. Build plan (milestones for you and the coding agent)

### Milestone 0 — Setup (1–2 days)
- Monorepo, Laravel + Next.js scaffolds, Docker Compose (api, web, mysql), `.env.example` files, CI skeleton.
- **Done when:** `docker compose up` serves both apps and CI is green.

### Milestone 1 — Auth (2–3 days)
- GitHub OAuth via Socialite, Sanctum token, Next.js login/callback/logout with httpOnly cookie, `/me`, protected routes.
- **Done when:** a real GitHub login lands on an empty dashboard showing the user's name and avatar.

### Milestone 2 — Ingestion and XP (4–6 days)
- `GithubService`, `SyncGithubEvents`, event normalization, `activity_events`, `XpService`, ledger, levels, caps, `xp:rebuild`, activity feed, dashboard XP bar.
- **Done when:** pushing a commit to a public repo results in XP on the dashboard within one sync cycle, and double-sync never double-counts.

### Milestone 3 — Streaks, quests, badges (5–7 days)
- `StreakService` and heatmap, quest templates and assignment jobs, progress updates, `BadgeService`, notifications and bell.
- **Done when:** a quest can be completed end to end with notification and XP reward.

### Milestone 4 — Social (4–6 days)
- Public profile, global leaderboard, guilds and weekly guild leaderboard, OG image, README badge SVG.
- **Done when:** a profile link shared on LinkedIn/Discord shows a rich preview and the badge renders in a GitHub README.

### Milestone 5 — Polish and launch (3–5 days)
- Landing page, animations, empty/error states, accessibility pass, rate limiting, security review, seed data, deploy, demo video/GIF, README with architecture diagram.
- **Done when:** the app is live on a public URL with a README that has screenshots and a "How it works" section.

### Milestone 6 — Stretch (optional)
- Discord webhooks, weekly digest, seasonal events, analytics charts.

---

## 15. Instructions for the coding agent

1. Read this whole document before writing code. Treat it as the source of truth; if something is ambiguous, choose the simplest option that satisfies the acceptance criteria and note the decision in `docs/decisions.md`.
2. Work milestone by milestone. Do not start the next milestone until the current one's "Done when" is true.
3. Keep controllers thin. Put logic in `app/Services`. Use Form Requests for validation, API Resources for output, Policies for authorization.
4. Every XP, streak, quest and badge rule must have unit tests before the feature is considered complete.
5. All time logic uses UTC storage and explicit timezone conversion. Never use server-local time.
6. Never log or return the GitHub token. Never commit secrets.
7. Use TypeScript strict mode. Share API response types in `apps/web/src/types`.
8. Commit in small, conventional commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
9. After each milestone, update `README.md` with run instructions and a short changelog.

### Environment variables

**API (`apps/api/.env.example`)**
```
APP_URL=
FRONTEND_URL=
DB_HOST= DB_PORT= DB_DATABASE= DB_USERNAME= DB_PASSWORD=
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI=
QUEUE_CONNECTION=database
CACHE_STORE=database
SANCTUM_STATEFUL_DOMAINS=
```

**Web (`apps/web/.env.example`)**
```
NEXT_PUBLIC_APP_URL=
API_BASE_URL=
```

---

## 16. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| GitHub Events API lag or 300-event cap | Missed activity | Document the limit, poll frequently, add GraphQL backfill later |
| XP farming (fake commits) | Unfair leaderboards | Daily caps, per-repo caps, duplicate filtering; add an admin flag to zero out abusers |
| Rate limiting at scale | Failed syncs | ETag, staggered scheduling, backoff, skip when low remaining |
| Timezone bugs | Wrong streaks | Centralize in one helper, heavy unit tests around midnight and DST |
| Scope creep | Never finishes | Stick to P0 first; stretch only after launch |

---

## 17. Success metrics

**Portfolio goals**
- Deployed on a public URL with a clean README, architecture diagram, screenshots and demo GIF.
- 80%+ test coverage on service classes.
- Lighthouse score 90+ on public pages.

**Product goals (if shared)**
- 50 real GitHub sign-ups.
- 30% of users return on 3+ different days in their first week.
- 20% of users copy the README badge.

---

## 18. Resume copy (use after you build it)

**CommitQuest — Gamified GitHub activity tracker** · Next.js, TypeScript, Tailwind, Laravel, MySQL, GitHub API
- Built a full-stack web app that converts real GitHub activity into XP, levels, streaks, quests and badges, with a Laravel REST API and a Next.js (App Router) frontend.
- Integrated GitHub OAuth and the Events API using ETag conditional requests and rate-limit backoff, with idempotent queued jobs for reliable ingestion.
- Designed an append-only XP ledger and anti-abuse rules (daily/per-repo caps) backed by unit tests for all scoring, streak and timezone logic.
- Shipped shareable growth features: dynamic OG images and a cached SVG README badge, plus guilds with weekly leaderboards.
- Set up CI (lint, type check, tests), Dockerized services and deployed web and API to production.

---

## 19. Glossary

- **Ledger:** append-only table of XP changes; the source of truth for XP.
- **ETag:** HTTP validator letting GitHub reply `304 Not Modified` without spending rate limit.
- **Idempotent job:** can run many times and produce the same result.
- **Guild:** a group of up to 50 users competing on a weekly leaderboard.
