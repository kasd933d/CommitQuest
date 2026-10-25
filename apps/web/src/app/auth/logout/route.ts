import { NextResponse, type NextRequest } from "next/server";
import { apiServer } from "@/lib/api";
import { clearToken, getToken } from "@/lib/session";

/**
 * Logout: revoke the Sanctum token server side, then drop the cookie.
 * Only POST is allowed so a third-party site can not log users out via a
 * crafted <img> or <a> tag (the SameSite=Lax cookie blocks cross-site POSTs).
 */
export async function POST(request: NextRequest): Promise<Response> {
  const token = await getToken();

  if (token) {
    try {
      await apiServer("/auth/logout", { method: "POST", token });
    } catch {
      // The token may already be revoked - clearing the cookie is enough.
    }
  }

  await clearToken();

  return NextResponse.redirect(new URL("/", request.url), 302);
}

export async function GET(): Promise<Response> {
  return new NextResponse("Method Not Allowed", {
    status: 405,
    headers: { Allow: "POST" },
  });
}
