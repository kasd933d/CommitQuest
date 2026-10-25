import "server-only";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

/**
 * The Sanctum token lives in an httpOnly cookie so browser JavaScript can
 * never read it (PRD §4.1). Only route handlers and server components touch it.
 */
export const TOKEN_COOKIE = "cq_token";
export const TOKEN_MAX_AGE = 60 * 60 * 24 * 30; // 30 days

const COOKIE_OPTIONS = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
  maxAge: TOKEN_MAX_AGE,
};

/** Read the API token (server only). */
export async function getToken(): Promise<string | undefined> {
  const store = await cookies();

  return store.get(TOKEN_COOKIE)?.value;
}

/** Store the API token after a successful OAuth exchange (server only). */
export async function setToken(token: string): Promise<void> {
  const store = await cookies();

  store.set(TOKEN_COOKIE, token, COOKIE_OPTIONS);
}

/** Remove the token cookie (server only). */
export async function clearToken(): Promise<void> {
  const store = await cookies();

  store.delete(TOKEN_COOKIE);
}

/**
 * Guard for authenticated server pages: redirects to /login when there is no
 * session, preserving the intended destination.
 */
export async function requireToken(): Promise<string> {
  const token = await getToken();

  if (!token) {
    redirect("/login");
  }

  return token;
}
