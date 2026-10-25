import { NextResponse, type NextRequest } from "next/server";
import { apiServer } from "@/lib/api";
import { setToken } from "@/lib/session";
import type { AuthResponse } from "@/types/api";

/**
 * Step 2 of the OAuth dance: exchange the GitHub code for an API token and
 * store it in an httpOnly cookie. The token is never exposed to browser JS.
 */
export async function GET(request: NextRequest): Promise<Response> {
  const { searchParams } = new URL(request.url);
  const code = searchParams.get("code");
  const state = searchParams.get("state");
  const oauthError = searchParams.get("error");

  if (oauthError || !code || !state) {
    return NextResponse.redirect(
      new URL(oauthError ? `/login?error=${oauthError}` : "/login?error=missing_code", request.url),
      302,
    );
  }

  try {
    const query = `code=${encodeURIComponent(code)}&state=${encodeURIComponent(state)}`;
    const auth = await apiServer<AuthResponse>(`/auth/github/callback?${query}`);

    await setToken(auth.token);

    return NextResponse.redirect(new URL("/dashboard", request.url), 302);
  } catch (error) {
    const message = error instanceof Error ? error.message : "sign_in_failed";

    return NextResponse.redirect(
      new URL(`/login?error=${encodeURIComponent(message)}`, request.url),
      302,
    );
  }
}
