import { Nav } from "@/components/nav";
import { SettingsForm } from "@/components/settings-form";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";
import { env } from "@/lib/env";
import { ApiRequestError, type Resource, type User } from "@/types/api";
import { redirect } from "next/navigation";

export const metadata = { title: "Settings" };

export default async function SettingsPage() {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  let user: User | null = null;

  try {
    const me = await apiServer<Resource<User>>("/me", { token });
    user = me.data;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 401) {
      await clearToken();
      redirect("/login");
    }
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

      <main id="main-content" className="mx-auto w-full max-w-3xl flex-1 px-4 py-8">
        <h1 className="text-2xl font-bold tracking-tight">Settings</h1>

        {user ? (
          <SettingsForm user={user} origin={env.APP_URL} />
        ) : (
          <p role="alert" className="panel mt-6 border-danger/40 p-4 text-sm text-danger">
            Could not load your settings. Please refresh.
          </p>
        )}
      </main>
    </>
  );
}
