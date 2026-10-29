import type { UserQuest } from "@/types/api";

/**
 * Quest card (PRD §4.5): progress bar, XP reward, status. Rewards are
 * auto-claimed by the API, so there is intentionally no claim button.
 */
export function QuestCard({ quest }: { quest: UserQuest }) {
  const percent = quest.target === 0 ? 0 : Math.min(100, Math.round((quest.progress / quest.target) * 100));
  const done = quest.status === "completed";
  const expired = quest.status === "expired";

  return (
    <article
      className={`panel flex flex-col gap-3 p-4 ${done ? "border-success/40" : expired ? "opacity-60" : ""}`}
    >
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-xs uppercase tracking-wide text-muted">
            {quest.period === "weekly" ? "👑 Weekly boss" : "🗡️ Daily quest"}
          </p>
          <h3 className="mt-1 font-semibold leading-tight">{quest.title}</h3>
        </div>
        <span className="shrink-0 rounded bg-primary/15 px-1.5 py-0.5 font-mono text-xs text-primary">
          +{quest.xp_reward} XP
        </span>
      </div>

      <p className="text-sm text-muted">{quest.description}</p>

      <div className="mt-auto">
        <div
          role="progressbar"
          aria-label={`${quest.title} progress`}
          aria-valuenow={percent}
          aria-valuemin={0}
          aria-valuemax={100}
          className="h-2 w-full overflow-hidden rounded-full bg-xp-track"
        >
          <div
            className={`h-full rounded-full transition-all ${done ? "bg-success" : "bg-primary"}`}
            style={{ width: `${percent}%` }}
          />
        </div>

        <div className="mt-1.5 flex items-center justify-between text-xs">
          <span className="font-mono tabular-nums text-muted">
            {quest.progress} / {quest.target}
          </span>
          {done ? (
            <span className="text-success">✓ Completed</span>
          ) : expired ? (
            <span className="text-muted">Expired</span>
          ) : (
            <span className="text-muted">{percent}%</span>
          )}
        </div>
      </div>
    </article>
  );
}
