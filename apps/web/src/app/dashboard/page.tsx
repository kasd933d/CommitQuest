import { Nav } from "@/components/nav";
import { XpBar } from "@/components/xp-bar";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import { ApiRequestError, type Resource, type User } from "@/types/api";
import { redirect } from "next/navigation";

export const metadata = { title: "Dashboard" };

export default async function DashboardPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let errorMessage: string | null = null;

  try {
    const response = await apiServer<Resource<User>>("/me", { token });
    user = response.data;
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

            <section className="panel mt-8 p-6" aria-labelledby="activity-heading">
              <h2 id="activity-heading" className="font-pixel text-[11px] text-primary">
                Recent activity
              </h2>

              <div className="mt-4 grid place-items-center gap-2 rounded border border-dashed border-border px-4 py-10 text-center">
                <p className="font-mono text-sm text-muted">No activity synced yet</p>
                <p className="max-w-md text-sm text-muted">
                  Your GitHub activity is synced automatically every 15 minutes.
                  Push a commit to a public repository and your first XP will show
                  up here — activity can take up to 15 minutes to appear.
                </p>
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
