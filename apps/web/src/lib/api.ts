import "server-only";
import { env } from "@/lib/env";
import { getToken } from "@/lib/session";
import { handle } from "@/lib/api-fetch";
import { ApiRequestError } from "@/types/api";

/**
 * Server-side API client. Used by route handlers and server components; it
 * attaches the token from the httpOnly cookie and talks straight to Laravel,
 * so the request never round-trips through our own public proxy.
 *
 * The browser-side counterpart lives in `lib/api-fetch.ts` (no `server-only`),
 * because Client Components cannot import this module.
 */
export async function apiServer<T>(
  path: string,
  init: RequestInit & { token?: string } = {},
): Promise<T> {
  const { token, ...rest } = init;
  const auth = token ?? (await getToken());

  const headers = new Headers(rest.headers);
  headers.set("Accept", "application/json");
  if (rest.body !== undefined && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  if (auth) {
    headers.set("Authorization", `Bearer ${auth}`);
  }

  let response: Response;

  try {
    response = await fetch(`${env.API_BASE_URL}/api/v1${path}`, {
      ...rest,
      headers,
      cache: "no-store",
    });
  } catch {
    throw new ApiRequestError(503, "The API is unreachable right now. Please try again.");
  }

  return handle<T>(response);
}
