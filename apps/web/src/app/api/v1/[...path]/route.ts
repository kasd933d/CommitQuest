import { NextResponse, type NextRequest } from "next/server";
import { cookies } from "next/headers";
import { env } from "@/lib/env";
import { TOKEN_COOKIE } from "@/lib/session";

/**
 * Thin proxy in front of the Laravel API (PRD §7).
 *
 * The browser only ever talks to /api/v1/* on this origin; the httpOnly token
 * cookie is read here, on the server, and forwarded as a Bearer header.
 */

type Context = { params: Promise<{ path: string[] }> };

async function proxy(request: NextRequest, { params }: Context): Promise<Response> {
  const { path } = await params;
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;

  const url = new URL(request.url);
  const target = `${env.API_BASE_URL}/api/v1/${path.join("/")}${url.search}`;

  const headers = new Headers();
  headers.set("Accept", "application/json");

  const contentType = request.headers.get("Content-Type");
  if (contentType) {
    headers.set("Content-Type", contentType);
  }
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  const hasBody = !["GET", "HEAD"].includes(request.method);
  const body = hasBody ? await request.text() : undefined;

  let upstream: Response;

  try {
    upstream = await fetch(target, {
      method: request.method,
      headers,
      body,
      cache: "no-store",
      redirect: "manual",
    });
  } catch {
    return NextResponse.json(
      { message: "The API is unreachable right now. Please try again." },
      { status: 502 },
    );
  }

  const responseHeaders = new Headers(upstream.headers);
  responseHeaders.delete("content-encoding");
  responseHeaders.delete("content-length");

  return new NextResponse(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: responseHeaders,
  });
}

export const GET = (request: NextRequest, context: Context) => proxy(request, context);
export const POST = (request: NextRequest, context: Context) => proxy(request, context);
export const PUT = (request: NextRequest, context: Context) => proxy(request, context);
export const PATCH = (request: NextRequest, context: Context) => proxy(request, context);
export const DELETE = (request: NextRequest, context: Context) => proxy(request, context);
