import { Nav } from "@/components/nav";
import { apiServer } from "@/lib/api";
import { getToken } from "@/lib/session";
import type { LeaderboardResponse, LeaderboardScope, Resource, User } from "@/types/api";
import Link from "next/link";

export const metadata = { title: "Leaderboard" };

const SCOPES: { scope: LeaderboardScope; label: string }[] = [
  { scope: "weekly", label: "This week" },
  { scope: "all", label: "All time" },
];

export default async function LeaderboardPage({
  searchParams,
}: {
  searchParams: Promise<{ scope?: string }>;
}) {
  const { scope: scopeParam } = await searchParams;
  const scope: LeaderboardScope = scopeParam === "all" ? "all" : "weekly";

  let board: LeaderboardResponse | null = null;
  let navUser: User | undefined;
  let errorMessage: string | null = null;

  try {
    const [leaderboard, token] = await Promise.all([
      apiServer<LeaderboardResponse>(`/leaderboard?scope=${scope}`),
      getToken(),
    ]);
    board = leaderboard;

    if (token) {
      try {
        const me = await apiServer<Resource<User>>("/me", { token });
        navUser = me.data;
      } catch {
        // Anonymous viewing is fine.
      }
    }
  } catch {
    errorMessage = "Something went wrong loading the leaderboard.";
  }

  return (
    <>
      <Nav
        user={
          navUser
            ? { username: navUser.username, avatar_url: navUser.avatar_url, level: navUser.level }
            : undefined
        }
      />

      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
        <h1 className="text-2xl font-bold tracking-tight">Leaderboard</h1>
        <p className="mt-1 text-sm text-muted">
          {scope === "weekly"
            ? "Top 100 by XP earned since Monday 00:00 UTC — resets every week."
            : "Top 100 by all-time XP. Private profiles are excluded."}
        </p>

        <nav aria-label="Leaderboard scope" className="mt-4 flex gap-2">
          {SCOPES.map((item) => (
            <Link
              key={item.scope}
              href={`/leaderboard?scope=${item.scope}`}
              className={`btn px-3 py-1.5 text-xs ${
                item.scope === scope ? "btn-primary" : "btn-ghost"
              }`}
              aria-current={item.scope === scope ? "page" : undefined}
            >
              {item.label}
            </Link>
          ))}
        </nav>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {board ? (
          board.entries.length > 0 ? (
            <ol className="panel mt-6 divide-y divide-border">
              {board.entries.map((entry) => (
                <li key={entry.user_id} className="flex items-center gap-4 p-3">
                  <span
                    className={`grid w-8 shrink-0 place-items-center rounded font-mono text-sm ${
                      entry.rank <= 3 ? "bg-primary/15 text-primary" : "text-muted"
                    }`}
                    aria-label={`Rank ${entry.rank}`}
                  >
                    {medal(entry.rank)}
                  </span>

                  {entry.avatar_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={entry.avatar_url}
                      alt=""
                      width={32}
                      height={32}
                      className="h-8 w-8 rounded-full border border-border"
                    />
                  ) : (
                    <span
                      aria-hidden
                      className="grid h-8 w-8 place-items-center rounded-full bg-surface-2 font-mono text-xs"
                    >
                      {entry.username.slice(0, 1).toUpperCase()}
                    </span>
                  )}

                  <div className="min-w-0 flex-1">
                    <Link
                      href={`/u/${entry.username}`}
                      className="truncate font-medium hover:underline"
                    >
                      {entry.display_name ?? entry.username}
                    </Link>
                    <p className="truncate font-mono text-xs text-muted">@{entry.username}</p>
                  </div>

                  <div className="text-right">
                    <p className="font-mono tabular-nums">{entry.xp.toLocaleString()} XP</p>
                    <p className="text-xs text-muted">Level {entry.level}</p>
                  </div>
                </li>
              ))}
            </ol>
          ) : (
            <p className="panel mt-6 p-6 text-sm text-muted">
              Nobody here yet — be the first to earn some XP.
            </p>
          )
        ) : null}
      </main>
    </>
  );
}

function medal(rank: number): string {
  if (rank === 1) return "🥇";
  if (rank === 2) return "🥈";
  if (rank === 3) return "🥉";
  return `#${rank}`;
}
