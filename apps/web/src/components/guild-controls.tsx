"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api-fetch";
import type { Guild } from "@/types/api";

/**
 * Guild management controls (PRD §4.7): leave (members), remove members and
 * regenerate the invite code (owner only — the API enforces that too).
 */
export function GuildControls({ guild, myUserId }: { guild: Guild; myUserId: number }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const isOwner = guild.owner_id === myUserId;

  async function run(action: () => Promise<unknown>, message: string): Promise<void> {
    setBusy(true);
    setNotice(null);

    try {
      await action();
      setNotice(message);
      router.refresh();
    } catch (caught) {
      setNotice(caught instanceof Error ? caught.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {isOwner && guild.invite_code ? (
        <button
          type="button"
          disabled={busy}
          onClick={() =>
            void run(async () => {
              const response = await apiFetch<{ invite_code: string }>(
                `/guilds/${guild.slug}/invite/regenerate`,
                { method: "POST" },
              );
              setNotice(`New invite code: ${response.invite_code}`);
            }, `New invite code generated.`)
          }
          className="btn btn-ghost px-3 py-1.5 text-xs"
        >
          Regenerate invite
        </button>
      ) : null}

      {!isOwner ? (
        <button
          type="button"
          disabled={busy}
          onClick={() =>
            void run(
              () => apiFetch(`/guilds/${guild.slug}/leave`, { method: "DELETE" }),
              "You left the guild.",
            )
          }
          className="btn btn-ghost px-3 py-1.5 text-xs text-danger"
        >
          {busy ? "Leaving…" : "Leave guild"}
        </button>
      ) : null}

      {notice ? (
        <p role="status" className="text-xs text-muted">
          {notice}
        </p>
      ) : null}
    </div>
  );
}

/** Owner-only member removal (PRD §4.7). */
export function RemoveMemberButton({
  guild,
  userId,
  username,
}: {
  guild: Guild;
  userId: number;
  username: string;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function remove(): Promise<void> {
    if (!window.confirm(`Remove @${username} from ${guild.name}?`)) return;

    setBusy(true);
    setError(null);

    try {
      await apiFetch(`/guilds/${guild.slug}/members/${userId}`, { method: "DELETE" });
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not remove the member.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <span className="flex items-center gap-2">
      <button
        type="button"
        onClick={() => void remove()}
        disabled={busy}
        className="text-xs text-danger hover:underline"
      >
        {busy ? "Removing…" : "Remove"}
      </button>
      {error ? (
        <span role="alert" className="text-xs text-danger">
          {error}
        </span>
      ) : null}
    </span>
  );
}
