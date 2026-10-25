import { ApiRequestError, type ApiError } from "@/types/api";

/**
 * Client-safe fetch helpers: no `server-only` import so Client Components can
 * use them. Everything goes through the Next.js proxy route, so the browser
 * only ever talks to `/api/v1/*` and never sees the GitHub token.
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

export async function handle<T>(response: Response): Promise<T> {
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
