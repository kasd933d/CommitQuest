# CommitQuest Web

Next.js 16 (App Router, TypeScript, Tailwind CSS 4) frontend for CommitQuest.
It acts as a thin proxy in front of the Laravel API and holds the GitHub token
in an httpOnly cookie so it is never readable by browser JavaScript.

Full documentation lives in the repository root:

- [`../../README.md`](../../README.md) — run instructions and changelog
- [`../../CommitQuest_PRD.md`](../../CommitQuest_PRD.md) — product requirements
- [`../../docs/decisions.md`](../../docs/decisions.md) — engineering decisions

```bash
npm install
cp .env.example .env.local
npm run dev          # http://localhost:3000
npm run lint && npm run typecheck && npm run test:unit -- --run && npm run build
```
