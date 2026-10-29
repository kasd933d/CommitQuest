import { apiServer } from "@/lib/api";
import type { PublicProfile } from "@/types/api";

/**
 * README badge (PRD §4.8): `GET /api/badge/{username}.svg` renders a
 * "CommitQuest · Lv 12 · 🔥 9 day streak" SVG for the user's GitHub README.
 * Cached for 10 minutes. Private or unknown profiles get a neutral badge so
 * the route never leaks whether an account exists.
 */
const CACHE_SECONDS = 600;

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ username: string }> },
) {
  // The path segment arrives as `{username}.svg`; strip the extension.
  const { username: raw } = await params;
  const username = decodeURIComponent(raw).replace(/\.svg$/i, "").trim();

  if (!/^[A-Za-z0-9-]{1,39}$/.test(username)) {
    return badgeResponse(badgeText("unknown", 0, 0));
  }

  let profile: PublicProfile | null = null;

  try {
    profile = await apiServer<PublicProfile>(`/users/${encodeURIComponent(username)}`);
  } catch {
    profile = null;
  }

  const text = profile
    ? badgeText(profile.username, profile.level, profile.current_streak)
    : badgeText(username, 0, 0);

  return badgeResponse(text);
}

function badgeText(username: string, level: number, streak: number): string {
  if (level === 0 && streak === 0) {
    return `CommitQuest · @${username}`;
  }

  // PRD §4.8 wording: "CommitQuest · Lv 12 · 🔥 9 day streak".
  return `CommitQuest · Lv ${level} · 🔥 ${streak} day streak`;
}

/** Minimal shields.io-style two-tone badge, escaped. */
function svg(label: string): string {
  // Rough width: label glyph estimate, clamped so it never overflows.
  const width = Math.min(420, Math.max(150, label.length * 7.6));
  const escaped = label
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;");

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="28" role="img" aria-label="${escaped}">
  <title>${escaped}</title>
  <linearGradient id="s" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <rect rx="4" width="${width}" height="28" fill="#0b1020"/>
  <rect x="0" y="0" width="${width}" height="28" fill="url(#s)"/>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="12">
    <text x="${width / 2}" y="18" fill="#eef1fb">${escaped}</text>
  </g>
</svg>`;
}

/** Wrap the SVG in a cached HTTP response. */
function badgeResponse(label: string): Response {
  return new Response(svg(label), {
    headers: {
      "Content-Type": "image/svg+xml; charset=utf-8",
      "Cache-Control": `public, max-age=${CACHE_SECONDS}, s-maxage=${CACHE_SECONDS}, stale-while-revalidate=${CACHE_SECONDS}`,
    },
  });
}
