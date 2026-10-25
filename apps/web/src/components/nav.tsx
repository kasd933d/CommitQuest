import Link from "next/link";

export interface NavUser {
  username: string;
  avatar_url: string | null;
  level: number;
}

const LINKS = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/leaderboard", label: "Leaderboard", disabled: true },
];

export function Nav({ user }: { user?: NavUser }) {
  return (
    <header className="border-b border-border bg-surface">
      <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-3">
        <Link href="/" className="font-pixel text-[13px] text-primary">
          CommitQuest
        </Link>

        <nav aria-label="Main" className="flex items-center gap-1 sm:gap-2">
          {LINKS.filter((link) => !link.disabled).map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded px-2 py-1.5 text-sm text-muted hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            >
              {link.label}
            </Link>
          ))}
        </nav>

        {user ? (
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-2 rounded px-2 py-1 text-sm">
              {user.avatar_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={user.avatar_url}
                  alt=""
                  width={24}
                  height={24}
                  className="h-6 w-6 rounded-full border border-border"
                />
              ) : (
                <span
                  aria-hidden
                  className="grid h-6 w-6 place-items-center rounded-full bg-surface-2 font-mono text-xs"
                >
                  {user.username.slice(0, 1).toUpperCase()}
                </span>
              )}
              <span className="hidden font-mono text-xs text-muted sm:inline">
                {user.username} · Lv {user.level}
              </span>
            </div>

            <form action="/auth/logout" method="post">
              <button
                type="submit"
                className="btn btn-ghost px-2 py-1 text-xs font-medium"
              >
                Sign out
              </button>
            </form>
          </div>
        ) : (
          <Link href="/login" className="btn btn-primary px-3 py-1.5 text-sm">
            Sign in
          </Link>
        )}
      </div>
    </header>
  );
}
