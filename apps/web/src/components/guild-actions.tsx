"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api-fetch";
import type { Guild } from "@/types/api";

/**
 * Guild creation + join forms (PRD §4.7). Pure client interactivity; the
 * list itself is server-rendered by /guilds.
 */
export function GuildActions({ myCount }: { myCount: number }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [isPublic, setIsPublic] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const atLimit = myCount >= 3;

  async function createGuild(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setSuccess(null);

    try {
      const guild = await apiFetch<{ data: Guild }>("/guilds", {
        method: "POST",
        body: JSON.stringify({ name, is_public: isPublic }),
      });
      setSuccess(`Created ${guild.data.name} — invite code: ${guild.data.invite_code}`);
      setName("");
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not create the guild.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={(event) => void createGuild(event)} className="panel p-4">
      <h2 className="font-semibold">Create a guild</h2>
      <p className="mt-1 text-xs text-muted">
        You can belong to at most 3 guilds (currently {myCount}/3).
      </p>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <label htmlFor="guild-name" className="sr-only">
          Guild name
        </label>
        <input
          id="guild-name"
          type="text"
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder="Guild name"
          minLength={3}
          maxLength={80}
          required
          disabled={atLimit || busy}
          className="min-w-0 flex-1 rounded border border-border bg-surface-2 px-3 py-1.5 text-sm placeholder:text-muted disabled:opacity-50"
        />

        <label className="flex items-center gap-1.5 text-xs text-muted">
          <input
            type="checkbox"
            checked={isPublic}
            onChange={(event) => setIsPublic(event.target.checked)}
            disabled={busy}
          />
          Public
        </label>

        <button type="submit" disabled={atLimit || busy || name.trim().length < 3} className="btn btn-primary px-3 py-1.5 text-xs">
          {busy ? "Creating…" : "Create"}
        </button>
      </div>

      {atLimit ? (
        <p className="mt-2 text-xs text-warning">Leave a guild before creating another.</p>
      ) : null}
      {error ? (
        <p role="alert" className="mt-2 text-xs text-danger">
          {error}
        </p>
      ) : null}
      {success ? (
        <p role="status" className="mt-2 text-xs text-success">
          {success}
        </p>
      ) : null}
    </form>
  );
}

/** Join a public guild or redeem an invite code (PRD §4.7). */
export function JoinGuildButton({ guild }: { guild: Guild }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function join(code?: string): Promise<void> {
    setBusy(true);
    setError(null);

    try {
      await apiFetch(`/guilds/${guild.slug}/join`, {
        method: "POST",
        body: JSON.stringify(code ? { invite_code: code } : {}),
      });
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not join.");
    } finally {
      setBusy(false);
    }
  }

  if (!guild.is_public) {
    return (
      <span className="flex items-center gap-1">
        <label htmlFor={`code-${guild.id}`} className="sr-only">
          Invite code
        </label>
        <input
          id={`code-${guild.id}`}
          type="text"
          placeholder="Invite code"
          disabled={busy}
          className="w-28 rounded border border-border bg-surface-2 px-2 py-1 text-xs"
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              void join((event.target as HTMLInputElement).value);
            }
          }}
        />
        <button
          type="button"
          onClick={() => {
            const input = document.getElementById(`code-${guild.id}`) as HTMLInputElement | null;
            void join(input?.value);
          }}
          disabled={busy}
          className="btn btn-ghost px-2 py-1 text-xs"
        >
          {busy ? "…" : "Join"}
        </button>
        {error ? (
          <span role="alert" className="text-xs text-danger">
            {error}
          </span>
        ) : null}
      </span>
    );
  }

  return (
    <span className="flex items-center gap-1">
      <button
        type="button"
        onClick={() => void join()}
        disabled={busy}
        className="btn btn-ghost px-2 py-1 text-xs"
      >
        {busy ? "Joining…" : "Join"}
      </button>
      {error ? (
        <span role="alert" className="text-xs text-danger">
          {error}
        </span>
      ) : null}
    </span>
  );
}
