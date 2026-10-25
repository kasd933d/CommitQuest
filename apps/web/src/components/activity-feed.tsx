import type { ActivityEvent } from "@/types/api";

const TONE: Record<ActivityEvent["type"], string> = {
  commit: "text-primary",
  pr_opened: "text-success",
  pr_merged: "text-success",
  pr_review: "text-accent",
  issue_closed: "text-warning",
  new_repo: "text-warning",
};

function formatWhen(iso: string): string {
  const date = new Date(iso);
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" });
}

/**
 * Recent activity list. Pure and presentational so it can be unit tested
 * without a server or a session.
 */
export function ActivityFeed({ events }: { events: ActivityEvent[] }) {
  if (events.length === 0) {
    return (
      <div
        className="grid place-items-center gap-2 rounded border border-dashed border-border px-4 py-10 text-center"
        data-testid="activity-empty"
      >
        <p className="font-mono text-sm text-muted">No activity synced yet</p>
        <p className="max-w-md text-sm text-muted">
          Your GitHub activity is synced automatically every 15 minutes. Push a commit to a public
          repository and your first XP will show up here — activity can take up to 15 minutes to
          appear.
        </p>
      </div>
    );
  }

  return (
    <ul className="divide-y divide-border" aria-label="Recent activity">
      {events.map((event) => (
        <li key={event.id} className="flex items-start gap-3 py-3" data-testid="activity-item">
          <span className={`mt-0.5 font-mono text-sm tabular-nums ${TONE[event.type]}`}>
            {event.xp_awarded > 0 ? `+${event.xp_awarded}` : "—"}
          </span>

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm">
              <span className="font-medium">{event.label}</span>
              <span className="text-muted"> · {event.repo_full_name}</span>
            </p>
            {event.message ? (
              <p className="truncate font-mono text-xs text-muted">{event.message}</p>
            ) : null}
          </div>

          <time
            dateTime={event.occurred_at}
            className="shrink-0 font-mono text-xs text-muted"
            title={formatWhen(event.occurred_at)}
          >
            {formatWhen(event.occurred_at)}
          </time>
        </li>
      ))}
    </ul>
  );
}
