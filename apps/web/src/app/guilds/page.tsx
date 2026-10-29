import { Nav } from "@/components/nav";
import { GuildActions, JoinGuildButton } from "@/components/guild-actions";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import { ApiRequestError, type GuildsResponse, type Resource, type User } from "@/types/api";
import Link from "next/link";
import { redirect } from "next/navigation";

export const metadata = { title: "Guilds" };

export default async function GuildsPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let guilds: GuildsResponse | null = null;
  let errorMessage: string | null = null;

  try {
    const [me, guildsResponse] = await Promise.all([
      apiServer<Resource<User>>("/me", { token }),
      apiServer<GuildsResponse>("/guilds", { token }),
    ]);
    user = me.data;
    guilds = guildsResponse;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      await clearToken();
      redirect("/login");
    }

    errorMessage =
      error instanceof ApiRequestError ? error.message : "Something went wrong loading guilds.";
  }

  return (
    <>
      <Nav
        user={
          user
            ? { username: user.username, avatar_url: user.avatar_url, level: user.level }
            : undefined
        }
      />

      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
        <h1 className="text-2xl font-bold tracking-tight">Guilds</h1>
        <p className="mt-1 text-sm text-muted">
          Team up for the weekly leaderboard. Everyone in a guild sees the same Monday-to-Sunday
          ranking by XP.
        </p>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {guilds ? (
          <>
            <div className="mt-6">
              <GuildActions myCount={guilds.mine.length} />
            </div>

            <section className="mt-8" aria-labelledby="mine-heading">
              <h2 id="mine-heading" className="font-pixel text-[11px] text-primary">
                My guilds ({guilds.mine.length}/3)
              </h2>

              {guilds.mine.length > 0 ? (
                <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                  {guilds.mine.map((guild) => (
                    <li key={guild.id} className="panel p-4">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <Link
                            href={`/guilds/${guild.slug}`}
                            className="font-semibold hover:underline"
                          >
                            🏰 {guild.name}
                          </Link>
                          <p className="mt-1 text-xs text-muted">
                            {guild.member_count} member{guild.member_count === 1 ? "" : "s"} ·{" "}
                            {guild.is_public ? "Public" : "Private"}
                          </p>
                          {guild.description ? (
                            <p className="mt-2 text-sm text-muted">{guild.description}</p>
                          ) : null}
                        </div>
                      </div>

                      {guild.invite_code ? (
                        <p className="mt-3 rounded bg-surface-2 px-2 py-1 font-mono text-xs text-muted">
                          Invite code: <span className="text-accent">{guild.invite_code}</span>
                        </p>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="panel mt-4 p-4 text-sm text-muted">
                  You are not in a guild yet — create one or join a public guild below.
                </p>
              )}
            </section>

            <section className="mt-10" aria-labelledby="discover-heading">
              <h2 id="discover-heading" className="font-pixel text-[11px] text-muted">
                Discover public guilds
              </h2>

              {guilds.discover.length > 0 ? (
                <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                  {guilds.discover.map((guild) => (
                    <li key={guild.id} className="panel flex items-start justify-between gap-3 p-4">
                      <div>
                        <p className="font-semibold">🏰 {guild.name}</p>
                        <p className="mt-1 text-xs text-muted">
                          {guild.member_count} member{guild.member_count === 1 ? "" : "s"}
                          {guild.owner_username ? ` · led by @${guild.owner_username}` : ""}
                        </p>
                        {guild.description ? (
                          <p className="mt-2 text-sm text-muted">{guild.description}</p>
                        ) : null}
                      </div>

                      <JoinGuildButton guild={guild} />
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="panel mt-4 p-4 text-sm text-muted">
                  No public guilds to join right now.
                </p>
              )}
            </section>
          </>
        ) : null}
      </main>
    </>
  );
}
