import type { HeatmapDay } from "@/types/api";

/**
 * 12-week contribution heatmap (PRD §4.4). Pure server-renderable markup:
 * the dashboard fetches the days and passes them in. Weeks are columns,
 * Monday rows — the API returns the days oldest-first in the user's timezone.
 */
export function Heatmap({ days }: { days: HeatmapDay[] }) {
  if (days.length === 0) {
    return null;
  }

  const activeDays = days.filter((day) => day.count > 0).length;
  const totalEvents = days.reduce((sum, day) => sum + day.count, 0);

  return (
    <section className="panel p-6" aria-labelledby="heatmap-heading">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="heatmap-heading" className="font-pixel text-[11px] text-primary">
          Last 12 weeks
        </h2>
        <p className="text-xs text-muted">
          {activeDays} active day{activeDays === 1 ? "" : "s"} · {totalEvents} event
          {totalEvents === 1 ? "" : "s"}
        </p>
      </div>

      <div className="mt-4 overflow-x-auto">
        <div
          className="grid w-max grid-flow-col grid-rows-7 gap-1"
          role="img"
          aria-label={`Activity heatmap: ${activeDays} active days in the last 12 weeks`}
        >
          {days.map((day) => (
            <span
              key={day.date}
              title={`${day.date}: ${day.count} event${day.count === 1 ? "" : "s"}`}
              className={`h-3 w-3 rounded-sm border border-border/40 ${level(day.count)}`}
            />
          ))}
        </div>
      </div>

      <div className="mt-3 flex items-center justify-end gap-1.5 text-[10px] text-muted">
        <span>Less</span>
        {[0, 1, 2, 3, 4].map((levelIndex) => (
          <span
            key={levelIndex}
            aria-hidden
            className={`h-2.5 w-2.5 rounded-sm border border-border/40 ${LEVELS[levelIndex]}`}
          />
        ))}
        <span>More</span>
      </div>
    </section>
  );
}

const LEVELS = [
  "bg-surface-2",
  "bg-primary/30",
  "bg-primary/50",
  "bg-primary/75",
  "bg-primary",
] as const;

function level(count: number): string {
  if (count === 0) return LEVELS[0];
  if (count === 1) return LEVELS[1];
  if (count === 2) return LEVELS[2];
  if (count <= 4) return LEVELS[3];
  return LEVELS[4];
}
