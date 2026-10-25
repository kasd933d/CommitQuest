import "server-only";
import { env } from "@/lib/env";
import { getToken } from "@/lib/session";
import { ApiRequestError, type ApiError } from "@/types/api";

/**
 * Server-side API client. Used by route handlers and server components; it
 * attaches the token from the httpOnly cookie and talks straight to Laravel,
 * so the request never round-trips through our own public proxy.
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

/**
 * Client-side helper: goes through the Next.js proxy route so the cookie stays
 * httpOnly and the browser never sees the token.
 */
export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body !== undefined && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  let response: Response;

  try {
    response = await fetch(`/api/v1${path}`, { ...init, headers, cache: "no-store" });
  } catch {
    throw new ApiRequestError(503, "The API is unreachable right now. Please try again.");
  }

  return handle<T>(response);
}

async function handle<T>(response: Response): Promise<T> {
  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  const payload: unknown = text ? safeJson(text) : null;

  if (!response.ok) {
    const error = (payload ?? {}) as ApiError;
    throw new ApiRequestError(
      response.status,
      error.message ?? `Request failed with status ${response.status}.`,
      error.errors,
    );
  }

  return payload as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}
