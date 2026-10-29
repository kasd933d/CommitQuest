import { Heatmap } from "@/components/heatmap";
import { Nav } from "@/components/nav";
import { apiServer } from "@/lib/api";
import { getToken } from "@/lib/session";
import { ApiRequestError, type PublicProfile } from "@/types/api";
import { notFound } from "next/navigation";

/**
 * Public profile page (PRD §4.8): server-rendered, shareable, with meta
 * tags for crawlers. Private profiles 404 (the API answers 404 too, so
 * existence is never confirmed).
 */
async function loadProfile(username: string): Promise<PublicProfile | null> {
  try {
    return await apiServer<PublicProfile>(`/users/${encodeURIComponent(username)}`);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) {
      return null;
    }

    // Other failures should not 404 a real profile; surface as not found
    // only when the API explicitly said so.
    throw error;
  }
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ username: string }>;
}) {
  const { username } = await params;
  const profile = await loadProfile(username);

  if (!profile) {
    return { title: "Profile not found" };
  }

  const name = profile.display_name ?? profile.username;
  const description = `${name} is level ${profile.level} on CommitQuest with ${profile.total_xp.toLocaleString()} XP and a ${profile.current_streak}-day streak.`;

  return {
    title: `${name} · CommitQuest`,
    description,
    openGraph: {
      title: `${name} — CommitQuest`,
      description,
      images: [`/u/${encodeURIComponent(username)}/opengraph-image`],
    },
    twitter: {
      card: "summary_large_image",
      title: `${name} — CommitQuest`,
      description,
    },
  };
}

export default async function PublicProfilePage({
  params,
}: {
  params: Promise<{ username: string }>;
}) {
  const { username } = await params;
  const profile = await loadProfile(username);

  if (!profile) {
    notFound();
  }

  const token = await getToken();
  let navUser: { username: string; avatar_url: string | null; level: number } | undefined;

  if (token) {
    try {
      const me = await apiServer<{ data: { username: string; avatar_url: string | null; level: number } }>("/me", { token });
      navUser = me.data;
    } catch {
      // Viewing a public profile while signed out is fine.
    }
  }

  const unlocked = profile.badges.filter((badge) => badge.unlocked);

  return (
    <>
      <Nav user={navUser} />

      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
        <article className="panel p-6">
          <div className="flex flex-wrap items-center gap-4">
            {profile.avatar_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={profile.avatar_url}
                alt=""
                width={64}
                height={64}
                className="h-16 w-16 rounded-full border-2 border-primary"
              />
            ) : (
              <span
                aria-hidden
                className="grid h-16 w-16 place-items-center rounded-full bg-surface-2 font-mono text-2xl"
              >
                {profile.username.slice(0, 1).toUpperCase()}
              </span>
            )}

            <div>
              <h1 className="text-2xl font-bold tracking-tight">
                {profile.display_name ?? profile.username}
              </h1>
              <p className="font-mono text-sm text-muted">@{profile.username}</p>
            </div>

            <div className="ml-auto grid grid-cols-2 gap-x-8 gap-y-2 text-right sm:grid-cols-4">
              <Stat label="Level" value={String(profile.level)} />
              <Stat label="XP" value={profile.total_xp.toLocaleString()} />
              <Stat label="Streak" value={`🔥 ${profile.current_streak}`} />
              <Stat label="Best" value={`${profile.longest_streak} days`} />
            </div>
          </div>
        </article>

        <div className="mt-8">
          <Heatmap days={profile.heatmap} />
        </div>

        <section className="mt-8" aria-labelledby="badges-heading">
          <h2 id="badges-heading" className="font-pixel text-[11px] text-primary">
            Badges ({unlocked.length}/{profile.badges.length})
          </h2>

          {unlocked.length > 0 ? (
            <ul className="mt-4 flex flex-wrap gap-3">
              {unlocked.map((badge) => (
                <li
                  key={badge.key}
                  title={`${badge.name} — ${badge.description}`}
                  className="panel flex items-center gap-2 px-3 py-2 text-sm"
                >
                  <span aria-hidden>{badge.icon}</span>
                  {badge.name}
                </li>
              ))}
            </ul>
          ) : (
            <p className="panel mt-4 p-4 text-sm text-muted">No badges unlocked yet.</p>
          )}
        </section>

        {profile.recent_quests.length > 0 ? (
          <section className="mt-8" aria-labelledby="quests-heading">
            <h2 id="quests-heading" className="font-pixel text-[11px] text-primary">
              Recent quests
            </h2>
            <ul className="mt-4 space-y-2">
              {profile.recent_quests.map((quest) => (
                <li
                  key={quest.id}
                  className="panel flex items-center justify-between gap-3 p-3 text-sm"
                >
                  <span className="text-muted">
                    {quest.icon} {quest.title}
                  </span>
                  <span className="font-mono text-xs">
                    {quest.status === "completed" ? (
                      <span className="text-success">✓ +{quest.xp_reward} XP</span>
                    ) : (
                      <span className="text-muted">
                        {quest.progress}/{quest.target}
                      </span>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </main>
    </>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-muted">{label}</p>
      <p className="font-mono text-xl tabular-nums">{value}</p>
    </div>
  );
}
