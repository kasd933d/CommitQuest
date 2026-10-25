"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api-fetch";
import { ApiRequestError, type SyncResponse, type SyncState } from "@/types/api";

/**
 * Manual "Sync now" button (PRD §4.2: throttled to once per 5 minutes per
 * user). The API enforces the cooldown; this component only reflects it.
 */
export function SyncButton({ sync }: { sync: SyncState }) {
  const router = useRouter();
  const [isPending, startTransition] = useTransition();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const coolingDown = sync.state === "cooldown";
  const disabled = busy || isPending || coolingDown;

  async function syncNow(): Promise<void> {
    setBusy(true);
    setNotice(null);

    try {
      await apiFetch<SyncResponse>("/me/sync", { method: "POST" });
      setNotice("Sync queued — this can take up to 15 minutes to show up.");
      startTransition(() => router.refresh());
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 429) {
        setNotice("Already synced recently. Try again in a few minutes.");
      } else if (error instanceof ApiRequestError) {
        setNotice(error.message);
      } else {
        setNotice("Something went wrong starting the sync.");
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <button
        type="button"
        onClick={syncNow}
        disabled={disabled}
        aria-busy={busy || isPending}
        className="rounded border border-primary/60 bg-primary/10 px-3 py-1.5 font-mono text-xs text-primary transition hover:bg-primary/20 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {busy || isPending ? "Syncing…" : coolingDown ? "On cooldown" : "Sync now"}
      </button>

      <p className="h-4 text-right text-xs text-muted" role="status">
        {notice ?? (coolingDown ? "Manual sync runs every 5 minutes." : "")}
      </p>
    </div>
  );
}
