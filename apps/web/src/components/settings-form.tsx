"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api-fetch";
import type { SyncResponse, User } from "@/types/api";

const TIMEZONES = [
  "UTC",
  "Europe/London",
  "Europe/Berlin",
  "Europe/Paris",
  "Europe/Madrid",
  "Europe/Warsaw",
  "America/New_York",
  "America/Chicago",
  "America/Denver",
  "America/Los_Angeles",
  "America/Sao_Paulo",
  "Africa/Cairo",
  "Africa/Lagos",
  "Asia/Dubai",
  "Asia/Karachi",
  "Asia/Kolkata",
  "Asia/Bangkok",
  "Asia/Shanghai",
  "Asia/Tokyo",
  "Asia/Seoul",
  "Australia/Sydney",
  "Pacific/Auckland",
];

/**
 * Settings form (PRD §4.10): timezone, profile visibility, manual sync,
 * account deletion, plus the sharing snippet from §4.8.
 */
export function SettingsForm({ user, origin }: { user: User; origin: string }) {
  const router = useRouter();
  const [timezone, setTimezone] = useState(user.timezone);
  const [isPublic, setIsPublic] = useState(user.is_public);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [syncing, setSyncing] = useState(false);

  const profileUrl = `${origin}/u/${user.username}`;
  const badgeMarkdown = `[![CommitQuest](${origin}/api/badge/${user.username}.svg)](${profileUrl})`;

  async function save(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setNotice(null);
    setError(null);

    try {
      await apiFetch("/me", {
        method: "PATCH",
        body: JSON.stringify({ timezone, is_public: isPublic }),
      });
      setNotice("Settings saved.");
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not save settings.");
    } finally {
      setBusy(false);
    }
  }

  async function syncNow(): Promise<void> {
    setSyncing(true);
    setNotice(null);
    setError(null);

    try {
      await apiFetch<SyncResponse>("/me/sync", { method: "POST" });
      setNotice("Sync queued — this can take up to 15 minutes to show up.");
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not start the sync.");
    } finally {
      setSyncing(false);
    }
  }

  async function deleteAccount(): Promise<void> {
    if (!window.confirm("Delete your account and all data? This cannot be undone.")) return;

    setBusy(true);
    setError(null);

    try {
      await apiFetch("/me", { method: "DELETE" });
      // The token is revoked server-side; a fresh load lands on the login
      // page (dashboard 401s and redirects).
      router.push("/");
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not delete the account.");
      setBusy(false);
    }
  }

  return (
    <div className="mt-6 space-y-6">
      <form onSubmit={(event) => void save(event)} className="panel space-y-4 p-6">
        <div>
          <label htmlFor="timezone" className="text-sm font-medium">
            Timezone
          </label>
          <p className="mt-1 text-xs text-muted">
            Streaks, quests and daily caps use this timezone (PRD §4.4).
          </p>
          <select
            id="timezone"
            value={timezone}
            onChange={(event) => setTimezone(event.target.value)}
            disabled={busy}
            className="mt-2 w-full max-w-xs rounded border border-border bg-surface-2 px-3 py-2 text-sm"
          >
            {(TIMEZONES.includes(user.timezone) ? TIMEZONES : [user.timezone, ...TIMEZONES]).map(
              (zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ),
            )}
          </select>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={isPublic}
            onChange={(event) => setIsPublic(event.target.checked)}
            disabled={busy}
          />
          Public profile (shown on the leaderboard and at {`/u/${user.username}`})
        </label>

        <div className="flex flex-wrap items-center gap-3">
          <button type="submit" disabled={busy} className="btn btn-primary px-4 py-2 text-sm">
            {busy ? "Saving…" : "Save settings"}
          </button>
          <button
            type="button"
            onClick={() => void syncNow()}
            disabled={syncing}
            className="btn btn-ghost px-4 py-2 text-sm"
          >
            {syncing ? "Syncing…" : "Sync now"}
          </button>
        </div>

        {notice ? (
          <p role="status" className="text-sm text-success">
            {notice}
          </p>
        ) : null}
        {error ? (
          <p role="alert" className="text-sm text-danger">
            {error}
          </p>
        ) : null}
      </form>

      <section className="panel p-6" aria-labelledby="share-heading">
        <h2 id="share-heading" className="font-semibold">
          Share your progress
        </h2>
        <p className="mt-1 text-xs text-muted">
          Paste this into a GitHub README — the badge updates every 10 minutes.
        </p>

        <CopyField label="README badge (Markdown)" value={badgeMarkdown} />
        <CopyField label="Profile link" value={profileUrl} />
      </section>

      <section className="panel border-danger/30 p-6" aria-labelledby="danger-heading">
        <h2 id="danger-heading" className="font-semibold text-danger">
          Danger zone
        </h2>
        <p className="mt-1 text-xs text-muted">
          Deleting your account removes all activity, XP, quests, badges and guild memberships.
        </p>
        <button
          type="button"
          onClick={() => void deleteAccount()}
          disabled={busy}
          className="btn mt-3 border-danger/50 px-4 py-2 text-sm text-danger"
        >
          Delete account
        </button>
      </section>
    </div>
  );
}

function CopyField({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard permission denied — the field is selectable manually.
    }
  }

  return (
    <div className="mt-3">
      <label className="text-xs uppercase tracking-wide text-muted">{label}</label>
      <div className="mt-1 flex items-center gap-2">
        <input
          readOnly
          value={value}
          onFocus={(event) => event.target.select()}
          className="min-w-0 flex-1 rounded border border-border bg-surface-2 px-3 py-2 font-mono text-xs"
        />
        <button type="button" onClick={() => void copy()} className="btn btn-ghost px-3 py-2 text-xs">
          {copied ? "Copied!" : "Copy"}
        </button>
      </div>
    </div>
  );
}
