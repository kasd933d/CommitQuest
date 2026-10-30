import Link from "next/link";
import { Nav } from "@/components/nav";
import { apiServer } from "@/lib/api";
import type { PlatformStats } from "@/types/api";

const FEATURES = [
  {
    title: "Real XP, real activity",
    body: "Every commit, pull request and review you land on GitHub becomes XP in an append-only ledger.",
  },
  {
    title: "Quests & streaks",
    body: "Three daily quests and one weekly boss quest keep the habit alive, with streak freezes for real life.",
  },
  {
    title: "Guilds & leaderboards",
    body: "Form a guild with your study group and race up the weekly leaderboard.",
  },
];

const HOW_IT_WORKS = [
  {
    step: "1",
    title: "Sign in with GitHub",
    body: "OAuth gives read-only access to your public events. The token never leaves the server.",
  },
  {
    step: "2",
    title: "Activity becomes XP",
    body: "Commits, PRs, merges and reviews are normalized into events, scored through the XP ledger and capped fairly.",
  },
  {
    step: "3",
    title: "Compete and share",
    body: "Quests, badges, streaks and guild leaderboards turn the habit into a game — show it off with your profile card and README badge.",
  },
];

/**
 * Landing page (M5, PRD §14). The stats row is fetched server-side with a
 * short revalidation window; if the API is down the numbers are simply
 * omitted instead of failing the page.
 */
async function loadStats(): Promise<PlatformStats | null> {
  try {
    return await apiServer<PlatformStats>("/stats");
  } catch {
    return null;
  }
}

export default async function HomePage() {
  const stats = await loadStats();

  return (
    <>
      <Nav />

      <main id="main-content" className="mx-auto w-full max-w-5xl flex-1 px-4 py-12 sm:py-16">
        <section className="mx-auto max-w-3xl text-center">
          <p className="font-pixel animate-pulse text-xs text-primary motion-reduce:animate-none">
            PRESS START
          </p>

          <h1 className="mt-4 text-4xl font-bold tracking-tight sm:text-5xl">
            Turn your GitHub activity into an RPG
          </h1>

          <p className="mt-4 text-lg text-muted">
            Commits, pull requests and code reviews earn XP, level you up, complete
            quests, unlock badges and push your guild up the weekly leaderboard.
          </p>

          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            <Link href="/auth/login" className="btn btn-primary px-5 py-3">
              Sign in with GitHub
            </Link>
            <Link href="/leaderboard" className="btn btn-ghost px-5 py-3">
              View leaderboard
            </Link>
          </div>

          <p className="mt-3 text-xs text-muted">
            Public activity only. Nothing in your private repositories is ever read.
          </p>
        </section>

        {stats ? (
          <section
            aria-label="Platform stats"
            className="mt-14 grid grid-cols-2 gap-4 sm:grid-cols-4"
          >
            <Stat label="Adventurers" value={stats.users.toLocaleString()} />
            <Stat label="XP earned" value={stats.total_xp.toLocaleString()} />
            <Stat label="Commits tracked" value={stats.commits.toLocaleString()} />
            <Stat label="Quests completed" value={stats.quests_completed.toLocaleString()} />
          </section>
        ) : null}

        <section aria-label="Features" className="mt-14 grid gap-4 sm:grid-cols-3">
          {FEATURES.map((feature) => (
            <article key={feature.title} className="panel p-5">
              <h2 className="font-pixel text-[11px] leading-relaxed text-primary">
                {feature.title}
              </h2>
              <p className="mt-3 text-sm text-muted">{feature.body}</p>
            </article>
          ))}
        </section>

        <section aria-labelledby="how-heading" className="mt-14">
          <h2 id="how-heading" className="text-center font-pixel text-[13px] text-primary">
            How it works
          </h2>

          <ol className="mt-6 grid gap-4 sm:grid-cols-3">
            {HOW_IT_WORKS.map((item) => (
              <li key={item.step} className="panel p-5">
                <span
                  aria-hidden
                  className="grid h-8 w-8 place-items-center rounded-full bg-primary/15 font-mono text-sm text-primary"
                >
                  {item.step}
                </span>
                <h3 className="mt-3 font-semibold">{item.title}</h3>
                <p className="mt-2 text-sm text-muted">{item.body}</p>
              </li>
            ))}
          </ol>
        </section>

        <section className="panel mx-auto mt-14 max-w-3xl p-8 text-center">
          <h2 className="text-2xl font-bold tracking-tight">Ready to play?</h2>
          <p className="mt-2 text-sm text-muted">
            Free, open source, and your data stays yours — delete everything with one
            click in settings.
          </p>
          <Link href="/auth/login" className="btn btn-primary mt-5 px-5 py-3">
            Sign in with GitHub
          </Link>
        </section>
      </main>

      <footer className="border-t border-border px-4 py-6 text-center text-xs text-muted">
        CommitQuest · built with Next.js, Laravel and the GitHub API
      </footer>
    </>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="panel p-4 text-center">
      <p className="font-mono text-2xl tabular-nums text-primary">{value}</p>
      <p className="mt-1 text-xs uppercase tracking-wide text-muted">{label}</p>
    </div>
  );
}
