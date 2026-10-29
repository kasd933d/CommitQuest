import { Nav } from "@/components/nav";
import { QuestCard } from "@/components/quest-card";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import { ApiRequestError, type QuestsResponse, type Resource, type User } from "@/types/api";
import { redirect } from "next/navigation";

export const metadata = { title: "Quests" };

export default async function QuestsPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let quests: QuestsResponse | null = null;
  let errorMessage: string | null = null;

  try {
    const [me, questsResponse] = await Promise.all([
      apiServer<Resource<User>>("/me", { token }),
      apiServer<QuestsResponse>("/me/quests", { token }),
    ]);
    user = me.data;
    quests = questsResponse;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      await clearToken();
      redirect("/login");
    }

    errorMessage =
      error instanceof ApiRequestError ? error.message : "Something went wrong loading your quests.";
  }

  const today = quests?.current.filter((quest) => quest.period === "daily") ?? [];
  const weekly = quests?.current.filter((quest) => quest.period === "weekly") ?? [];
  const recent = quests?.recent ?? [];

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
        <h1 className="text-2xl font-bold tracking-tight">Quests</h1>
        <p className="mt-1 text-sm text-muted">
          Three daily quests and one weekly boss, refreshed automatically. Progress tracks your
          GitHub activity — rewards are claimed the moment a quest completes.
        </p>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {quests ? (
          <>
            <section className="mt-8" aria-labelledby="daily-heading">
              <h2 id="daily-heading" className="font-pixel text-[11px] text-primary">
                🗡️ Daily quests
              </h2>
              {today.length > 0 ? (
                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                  {today.map((quest) => (
                    <QuestCard key={quest.id} quest={quest} />
                  ))}
                </div>
              ) : (
                <p className="panel mt-4 p-4 text-sm text-muted">
                  No daily quests right now — they refresh at midnight in your timezone (
                  {user?.timezone ?? "UTC"}).
                </p>
              )}
            </section>

            <section className="mt-10" aria-labelledby="weekly-heading">
              <h2 id="weekly-heading" className="font-pixel text-[11px] text-primary">
                👑 Weekly boss
              </h2>
              {weekly.length > 0 ? (
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                  {weekly.map((quest) => (
                    <QuestCard key={quest.id} quest={quest} />
                  ))}
                </div>
              ) : (
                <p className="panel mt-4 p-4 text-sm text-muted">
                  No weekly boss assigned yet — it appears at the start of your local week.
                </p>
              )}
            </section>

            {recent.length > 0 ? (
              <section className="mt-10" aria-labelledby="recent-heading">
                <h2 id="recent-heading" className="font-pixel text-[11px] text-muted">
                  Recently finished
                </h2>
                <ul className="mt-4 space-y-2">
                  {recent.map((quest) => (
                    <li
                      key={quest.id}
                      className="panel flex items-center justify-between gap-3 p-3 text-sm"
                    >
                      <span className="text-muted">
                        {quest.icon} {quest.title}
                      </span>
                      <span className="flex items-center gap-3 font-mono text-xs">
                        <span className="text-muted">
                          {quest.progress}/{quest.target}
                        </span>
                        {quest.status === "completed" ? (
                          <span className="text-success">+{quest.xp_reward} XP</span>
                        ) : (
                          <span className="text-muted">Expired</span>
                        )}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
          </>
        ) : null}
      </main>
    </>
  );
}
