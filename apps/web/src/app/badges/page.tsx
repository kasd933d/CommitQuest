import { Nav } from "@/components/nav";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import { ApiRequestError, type Badge, type Resource, type User } from "@/types/api";
import { redirect } from "next/navigation";

export const metadata = { title: "Badges" };

const RARITY_ORDER = ["legendary", "epic", "rare", "common"] as const;

const RARITY_STYLES: Record<Badge["rarity"], string> = {
  common: "border-border text-muted",
  rare: "border-accent/50 text-accent",
  epic: "border-primary/50 text-primary",
  legendary: "border-warning/60 text-warning",
};

export default async function BadgesPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;
  let badges: Badge[] = [];
  let errorMessage: string | null = null;

  try {
    const [me, badgesResponse] = await Promise.all([
      apiServer<Resource<User>>("/me", { token }),
      apiServer<{ data: Badge[] }>("/me/badges", { token }),
    ]);
    user = me.data;
    badges = badgesResponse.data;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      await clearToken();
      redirect("/login");
    }

    errorMessage =
      error instanceof ApiRequestError ? error.message : "Something went wrong loading your badges.";
  }

  const unlocked = badges.filter((badge) => badge.unlocked);
  const sorted = [...badges].sort(
    (a, b) => RARITY_ORDER.indexOf(a.rarity) - RARITY_ORDER.indexOf(b.rarity),
  );

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
        <h1 className="text-2xl font-bold tracking-tight">Badges</h1>
        <p className="mt-1 text-sm text-muted">
          {unlocked.length} of {badges.length} unlocked
        </p>

        {errorMessage ? (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            {errorMessage}
          </p>
        ) : null}

        {badges.length > 0 ? (
          <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {sorted.map((badge) => (
              <li
                key={badge.key}
                className={`panel flex gap-4 p-4 ${badge.unlocked ? "" : "opacity-60"}`}
              >
                <span
                  aria-hidden
                  className={`grid h-12 w-12 shrink-0 place-items-center rounded-lg border bg-surface-2 text-2xl ${badge.unlocked ? RARITY_STYLES[badge.rarity] : "border-border text-muted grayscale"}`}
                >
                  {badge.icon}
                </span>

                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold leading-tight">{badge.name}</h2>
                    <span
                      className={`rounded border px-1.5 py-0.5 text-[10px] uppercase tracking-wide ${RARITY_STYLES[badge.rarity]}`}
                    >
                      {badge.rarity}
                    </span>
                  </div>

                  <p className="mt-1 text-sm text-muted">{badge.description}</p>

                  <p className="mt-1 text-xs">
                    {badge.unlocked ? (
                      <span className="text-success">
                        ✓ Unlocked{" "}
                        {badge.unlocked_at
                          ? new Date(badge.unlocked_at).toLocaleDateString("en-GB", {
                              dateStyle: "medium",
                            })
                          : ""}
                      </span>
                    ) : (
                      <span className="text-muted">Locked</span>
                    )}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        ) : null}
      </main>
    </>
  );
}
