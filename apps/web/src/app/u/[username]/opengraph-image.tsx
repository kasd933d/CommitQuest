import { apiServer } from "@/lib/api";
import type { PublicProfile } from "@/types/api";
import { ImageResponse } from "next/og";

export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "CommitQuest profile card";

/**
 * Dynamic OG image for public profiles (PRD §4.8): avatar, level, XP and
 * streak on the app's dark "night realm" palette.
 */
export default async function OpengraphImage({
  params,
}: {
  params: Promise<{ username: string }>;
}) {
  const { username } = await params;

  let profile: PublicProfile | null = null;

  try {
    profile = await apiServer<PublicProfile>(`/users/${encodeURIComponent(username)}`);
  } catch {
    profile = null;
  }

  const name = profile?.display_name ?? profile?.username ?? username;
  const level = profile?.level ?? 1;
  const xp = profile?.total_xp ?? 0;
  const streak = profile?.current_streak ?? 0;

  const avatarUrl = profile?.avatar_url;
  const avatar = avatarUrl ? await fetchAvatar(avatarUrl) : null;

  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          backgroundColor: "#0b1020",
          color: "#eef1fb",
          padding: 64,
          fontFamily: "sans-serif",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 32 }}>
          {avatar ? (
            // Satori renders this server-side; next/image is not applicable.
            // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
            <img
              src={avatar}
              width={128}
              height={128}
              style={{ borderRadius: 9999, border: "4px solid #f5c518" }}
            />
          ) : (
            <div
              style={{
                width: 128,
                height: 128,
                borderRadius: 9999,
                backgroundColor: "#1c2440",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontSize: 56,
              }}
            >
              {username.slice(0, 1).toUpperCase()}
            </div>
          )}

          <div style={{ display: "flex", flexDirection: "column" }}>
            <div style={{ fontSize: 52, fontWeight: 700 }}>{name}</div>
            <div style={{ fontSize: 28, color: "#a9b2cc" }}>@{profile?.username ?? username}</div>
          </div>
        </div>

        <div style={{ display: "flex", gap: 48 }}>
          <Metric label="LEVEL" value={String(level)} color="#f5c518" />
          <Metric label="XP" value={xp.toLocaleString()} color="#7dd3fc" />
          <Metric label="STREAK" value={`🔥 ${streak}`} color="#4ade80" />
        </div>

        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <div style={{ fontSize: 30, color: "#f5c518", fontWeight: 700 }}>CommitQuest</div>
          <div style={{ fontSize: 22, color: "#a9b2cc" }}>github activity, gamified</div>
        </div>
      </div>
    ),
    size,
  );
}

function Metric({ label, value, color }: { label: string; value: string; color: string }) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
      <div style={{ fontSize: 20, color: "#a9b2cc", letterSpacing: 2 }}>{label}</div>
      <div style={{ fontSize: 44, color, fontWeight: 700 }}>{value}</div>
    </div>
  );
}

/** Inline the avatar as a data URL so ImageResponse needs no network fetch. */
async function fetchAvatar(url: string): Promise<string | null> {
  try {
    const response = await fetch(url);

    if (!response.ok) return null;

    const buffer = await response.arrayBuffer();
    const base64 = Buffer.from(buffer).toString("base64");

    return `data:${response.headers.get("content-type") ?? "image/png"};base64,${base64}`;
  } catch {
    return null;
  }
}
