import Link from "next/link";
import { Nav } from "@/components/nav";

const ERROR_MESSAGES: Record<string, string> = {
  access_denied: "You cancelled the sign-in. Try again whenever you are ready.",
  missing_code: "GitHub did not send us back a code. Please try again.",
  state_mismatch: "That sign-in link expired. Please try again.",
  unavailable: "The API is unreachable right now. Please try again shortly.",
};

const DEFAULT_MESSAGE =
  "Sign-in did not complete. Please try again — if it keeps failing, revoke the CommitQuest app in your GitHub settings first.";

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const { error } = await searchParams;
  const message = error ? (ERROR_MESSAGES[error] ?? DEFAULT_MESSAGE) : null;

  return (
    <>
      <Nav />

      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-16">
        <section className="panel p-6 sm:p-8">
          <h1 className="font-pixel text-sm text-primary">Sign in</h1>

          <p className="mt-4 text-sm text-muted">
            CommitQuest uses your public GitHub activity only — no code, no
            private repositories, no email.
          </p>

          {message ? (
            <p
              role="alert"
              className="mt-4 rounded border border-danger/40 bg-surface-2 p-3 text-sm text-danger"
            >
              {message}
            </p>
          ) : null}

          <a href="/auth/login" className="btn btn-primary mt-6 w-full px-4 py-3">
            <GitHubIcon />
            Continue with GitHub
          </a>

          <p className="mt-4 text-center text-xs text-muted">
            <Link href="/" className="underline underline-offset-2 hover:text-foreground">
              Back to home
            </Link>
          </p>
        </section>
      </main>
    </>
  );
}

function GitHubIcon() {
  return (
    <svg aria-hidden viewBox="0 0 16 16" width="18" height="18" fill="currentColor">
      <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z" />
    </svg>
  );
}
