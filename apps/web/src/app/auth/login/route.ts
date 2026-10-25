import { NextResponse, type NextRequest } from "next/server";
import { apiServer } from "@/lib/api";

/**
 * Step 1 of the OAuth dance: ask Laravel for the GitHub authorize URL and send
 * the browser there. Kept on our origin so GitHub can never be tricked into
 * redirecting anywhere else.
 */
export async function GET(request: NextRequest): Promise<Response> {
  try {
    const { url } = await apiServer<{ url: string }>("/auth/github/redirect");

    return NextResponse.redirect(url, 302);
  } catch {
    return NextResponse.redirect(
      new URL("/login?error=unavailable", request.url),
      302,
    );
  }
}
