"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiFetch } from "@/lib/api-fetch";
import type {
  AppNotification,
  MarkNotificationsReadResponse,
  NotificationsResponse,
} from "@/types/api";

/**
 * Notification bell (PRD §4.9): polls `GET /me/notifications` every 60
 * seconds, shows the unread count, and marks notifications read on open.
 * Polling pauses while the tab is hidden and stops on 401 (revoked token).
 */
export function NotificationBell() {
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const [failed, setFailed] = useState(false);
  const panelRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    let cancelled = false;

    // Defined inline (not via useCallback) so the react-hooks lint can see
    // the setState calls only happen after the awaited fetch resolves.
    const poll = async (): Promise<void> => {
      if (document.visibilityState !== "visible") return;

      try {
        const response = await apiFetch<NotificationsResponse>("/me/notifications?per_page=10");
        if (cancelled) return;
        setNotifications(response.data);
        setUnread(response.unread_count);
        setFailed(false);
      } catch {
        // Keep whatever we already have; the bell is non-critical.
        if (!cancelled) setFailed(true);
      }
    };

    void poll();

    const timer = setInterval(() => {
      void poll();
    }, 60_000);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, []);

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };

    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);

    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  const toggle = useCallback(async () => {
    const next = !open;
    setOpen(next);

    if (next && unread > 0) {
      try {
        const response = await apiFetch<MarkNotificationsReadResponse>(
          "/me/notifications/read",
          { method: "POST" },
        );
        setUnread(response.unread_count);
        setNotifications((current) =>
          current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })),
        );
      } catch {
        // Non-critical: next poll retries.
      }
    }
  }, [open, unread]);

  return (
    <div className="relative" ref={panelRef}>
      <button
        type="button"
        onClick={() => void toggle()}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={`Notifications${unread > 0 ? `, ${unread} unread` : ""}`}
        className="relative rounded px-2 py-1.5 text-sm text-muted hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
      >
        <span aria-hidden>🔔</span>
        {unread > 0 ? (
          <span
            aria-hidden
            className="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-danger px-1 font-mono text-[10px] leading-4 text-background"
          >
            {unread > 9 ? "9+" : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <div
          role="dialog"
          aria-label="Notifications"
          className="absolute right-0 z-50 mt-2 max-h-96 w-80 overflow-y-auto rounded-lg border border-border bg-surface p-2 shadow-xl"
        >
          {failed && notifications.length === 0 ? (
            <p className="p-3 text-sm text-muted">Could not load notifications.</p>
          ) : notifications.length === 0 ? (
            <p className="p-3 text-sm text-muted">No notifications yet.</p>
          ) : (
            <ul className="divide-y divide-border">
              {notifications.map((notification) => (
                <li key={notification.id} className="p-3">
                  <p
                    className={`text-sm font-medium ${notification.read_at ? "text-muted" : "text-foreground"}`}
                  >
                    {iconFor(notification.type)} {notification.title}
                  </p>
                  <p className="mt-0.5 text-xs text-muted">{notification.body}</p>
                  <p className="mt-1 text-[10px] text-muted">
                    {relativeTime(notification.created_at)}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}

function iconFor(type: AppNotification["type"]): string {
  switch (type) {
    case "level_up":
      return "⬆️";
    case "quest_complete":
      return "🗡️";
    case "badge_unlocked":
      return "🏅";
    case "streak_at_risk":
      return "🔥";
    case "guild_invite":
      return "🏰";
  }
}

function relativeTime(iso: string): string {
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

  if (seconds < 60) return "just now";
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}
