import Link from "next/link";
import { Nav } from "@/components/nav";

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

export default function HomePage() {
  return (
    <>
      <Nav />

      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-12 sm:py-16">
        <section className="mx-auto max-w-3xl text-center">
          <p className="font-pixel text-xs text-primary">PRESS START</p>

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
          </div>

          <p className="mt-3 text-xs text-muted">
            Public activity only. Nothing in your private repositories is ever read.
          </p>
        </section>

        <section className="mt-14 grid gap-4 sm:grid-cols-3">
          {FEATURES.map((feature) => (
            <article key={feature.title} className="panel p-5">
              <h2 className="font-pixel text-[11px] leading-relaxed text-primary">
                {feature.title}
              </h2>
              <p className="mt-3 text-sm text-muted">{feature.body}</p>
            </article>
          ))}
        </section>
      </main>

      <footer className="border-t border-border px-4 py-6 text-center text-xs text-muted">
        CommitQuest · built with Next.js, Laravel and the GitHub API
      </footer>
    </>
  );
}
