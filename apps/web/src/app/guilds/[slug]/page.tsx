import { Nav } from "@/components/nav";
import { GuildControls, RemoveMemberButton } from "@/components/guild-controls";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import {
  ApiRequestError,
  type GuildDetailResponse,
  type Resource,
  type User,
} from "@/types/api";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";

export const metadata = { title: "Guild" };

export default async function GuildDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let detail: GuildDetailResponse | null = null;
  let errorMessage: string | null = null;
  let missing = false;

  try {
    const [me, guildResponse] = await Promise.all([
      apiServer<Resource<User>>("/me", { token }),
      apiServer<GuildDetailResponse>(`/guilds/${encodeURIComponent(slug)}`, { token }),
    ]);
    user = me.data;
    detail = guildResponse;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      await clearToken();
      redirect("/login");
    }

    if (error instanceof ApiRequestError && error.status === 404) {
      missing = true;
    } else {
      errorMessage =
        error instanceof ApiRequestError ? error.message : "Something went wrong.";
    }
  }

  if (missing) {
    notFound();
  }

  const guild = detail?.guild;

  return (
    <>
      <Nav
        user={
          user
            ? { username: user.username, avatar_url: user.avatar_url, level: user.level }
            : undefined
        }
      />

      <main id="main-content" className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
        <Link href="/guilds" className="text-xs text-accent hover:underline">
          ← All guilds
        </Link>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {guild ? (
          <>
            <article className="panel mt-4 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h1 className="text-2xl font-bold tracking-tight">🏰 {guild.name}</h1>
                  <p className="mt-1 text-xs text-muted">
                    {guild.member_count} member{guild.member_count === 1 ? "" : "s"} ·{" "}
                    {guild.is_public ? "Public" : "Private"}
                    {guild.owner_username ? ` · led by @${guild.owner_username}` : ""}
                  </p>
                  {guild.description ? (
                    <p className="mt-3 text-sm text-muted">{guild.description}</p>
                  ) : null}
                </div>

                {user && guild.is_member ? (
                  <GuildControls guild={guild} myUserId={user.id} />
                ) : null}
              </div>

              {guild.is_member && guild.invite_code ? (
                <p className="mt-4 rounded bg-surface-2 px-3 py-2 font-mono text-xs text-muted">
                  Invite code: <span className="text-accent">{guild.invite_code}</span> — share it
                  to bring friends in.
                </p>
              ) : null}
            </article>

            <section className="mt-8" aria-labelledby="board-heading">
              <h2 id="board-heading" className="font-pixel text-[11px] text-primary">
                👑 Weekly leaderboard
              </h2>
              <p className="mt-1 text-xs text-muted">
                Ranked by XP earned this week (Monday 00:00 UTC → Sunday 23:59 UTC).
              </p>

              {detail && detail.leaderboard.length > 0 ? (
                <ol className="panel mt-4 divide-y divide-border">
                  {detail.leaderboard.map((entry) => (
                    <li key={entry.user_id} className="flex items-center gap-4 p-3">
                      <span
                        className={`grid w-8 shrink-0 place-items-center rounded font-mono text-sm ${
                          entry.rank <= 3 ? "bg-primary/15 text-primary" : "text-muted"
                        }`}
                        aria-label={`Rank ${entry.rank}`}
                      >
                        {entry.rank === 1 ? "🥇" : entry.rank === 2 ? "🥈" : entry.rank === 3 ? "🥉" : `#${entry.rank}`}
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

                      <div className="flex items-center gap-4">
                        <p className="font-mono tabular-nums">{entry.weekly_xp.toLocaleString()} XP</p>

                        {user && guild.owner_id === user.id && entry.user_id !== user.id ? (
                          <RemoveMemberButton
                            guild={guild}
                            userId={entry.user_id}
                            username={entry.username}
                          />
                        ) : null}
                      </div>
                    </li>
                  ))}
                </ol>
              ) : (
                <p className="panel mt-4 p-6 text-sm text-muted">
                  No XP earned yet this week — the board fills up as members code.
                </p>
              )}
            </section>
          </>
        ) : null}
      </main>
    </>
  );
}
