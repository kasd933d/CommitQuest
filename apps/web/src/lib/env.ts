/**
 * Runtime configuration.
 *
 * API_BASE_URL is read server-side only (route handlers and server components),
 * NEXT_PUBLIC_* is inlined into the browser bundle.
 */
export const env = {
  API_BASE_URL: process.env.API_BASE_URL ?? "http://localhost:8000",
  APP_URL: process.env.NEXT_PUBLIC_APP_URL ?? "http://localhost:3000",
  isServer: typeof window === "undefined",
} as const;
