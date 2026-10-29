import { Nav } from "@/components/nav";
import { ActivityFeed } from "@/components/activity-feed";
import { Heatmap } from "@/components/heatmap";
import { QuestCard } from "@/components/quest-card";
import { SyncButton } from "@/components/sync-button";
import { XpBar } from "@/components/xp-bar";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import {
  ApiRequestError,
  type ActivityFeed as ActivityFeedResponse,
  type HeatmapResponse,
  type QuestsResponse,
  type Resource,
  type User,
} from "@/types/api";
import { redirect } from "next/navigation";

export const metadata = { title: "Dashboard" };

export default async function DashboardPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let feed: ActivityFeedResponse | null = null;
  let heatmap: HeatmapResponse | null = null;
  let quests: QuestsResponse | null = null;
  let errorMessage: string | null = null;

  try {
    const [me, activity, heatmapResponse, questsResponse] = await Promise.all([
      apiServer<Resource<User>>("/me", { token }),
      apiServer<ActivityFeedResponse>("/me/activity", { token }),
      apiServer<HeatmapResponse>("/me/heatmap", { token }),
      apiServer<QuestsResponse>("/me/quests", { token }),
    ]);
    user = me.data;
    feed = activity;
    heatmap = heatmapResponse;
    quests = questsResponse;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      // The token was revoked (logout elsewhere, account deleted, expired).
      await clearToken();
      redirect("/login");
    }

    errorMessage =
      error instanceof ApiRequestError ? error.message : "Something went wrong loading your profile.";
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
        <h1 className="text-2xl font-bold tracking-tight">
          {user ? `Welcome back, ${user.display_name ?? user.username}` : "Dashboard"}
        </h1>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {user ? (
          <>
            <div className="mt-6 grid gap-4 lg:grid-cols-3">
              <div className="lg:col-span-2">
                <XpBar
                  level={user.level}
                  progress={user.level_progress}
                  totalXp={user.total_xp}
                  xpToNextLevel={user.xp_to_next_level}
                />
              </div>

              <StatCard label="Streak" value={`🔥 ${user.current_streak} day${user.current_streak === 1 ? "" : "s"}`} hint={`Best: ${user.longest_streak} days`} />
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-3">
              <StatCard label="Total XP" value={user.total_xp.toLocaleString()} hint={`Level ${user.level}`} />
              <StatCard label="Streak freezes" value={String(user.streak_freezes)} hint="Earned every 7-day streak" />
              <SyncCard status={user.sync_status} lastSyncedAt={user.last_synced_at} />
            </div>

            {heatmap ? (
              <div className="mt-8">
                <Heatmap days={heatmap.days} />
              </div>
            ) : null}

            {quests && quests.current.length > 0 ? (
              <section className="mt-8" aria-labelledby="quests-heading">
                <div className="flex items-baseline justify-between">
                  <h2 id="quests-heading" className="font-pixel text-[11px] text-primary">
                    Active quests
                  </h2>
                  <a href="/quests" className="text-xs text-accent hover:underline">
                    All quests →
                  </a>
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                  {quests.current.map((quest) => (
                    <QuestCard key={quest.id} quest={quest} />
                  ))}
                </div>
              </section>
            ) : null}

            <section className="panel mt-8 p-6" aria-labelledby="activity-heading">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h2 id="activity-heading" className="font-pixel text-[11px] text-primary">
                    Recent activity
                  </h2>
                  <p className="mt-1 text-xs text-muted">
                    Synced from the GitHub Events API · may lag by a few minutes
                  </p>
                </div>

                {feed ? <SyncButton sync={feed.sync} /> : null}
              </div>

              <div className="mt-4">
                <ActivityFeed events={feed?.data ?? []} />
              </div>
            </section>
          </>
        ) : null}
      </main>
    </>
  );
}

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <article className="panel p-4">
      <p className="text-xs uppercase tracking-wide text-muted">{label}</p>
      <p className="mt-1 font-mono text-2xl tabular-nums">{value}</p>
      {hint ? <p className="mt-1 text-xs text-muted">{hint}</p> : null}
    </article>
  );
}

function SyncCard({
  status,
  lastSyncedAt,
}: {
  status: User["sync_status"];
  lastSyncedAt: string | null;
}) {
  const tone =
    status === "ok"
      ? "text-success"
      : status === "needs_reauth"
        ? "text-warning"
        : "text-danger";

  const label =
    status === "ok"
      ? "Connected"
      : status === "needs_reauth"
        ? "Needs re-auth"
        : "Sync error";

  const synced = lastSyncedAt
    ? `Last sync ${new Date(lastSyncedAt).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" })}`
    : "Not synced yet";

  return (
    <article className="panel p-4">
      <p className="text-xs uppercase tracking-wide text-muted">GitHub sync</p>
      <p className={`mt-1 font-mono text-lg ${tone}`}>{label}</p>
      <p className="mt-1 text-xs text-muted">{synced}</p>
    </article>
  );
}
