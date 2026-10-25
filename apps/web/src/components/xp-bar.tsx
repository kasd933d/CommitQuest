interface XpBarProps {
  level: number;
  /** 0–100 progress through the current level. */
  progress: number;
  totalXp: number;
  xpToNextLevel: number;
}

/**
 * Level progress bar shown on the dashboard and public profiles.
 */
export function XpBar({ level, progress, totalXp, xpToNextLevel }: XpBarProps) {
  const clamped = Math.min(100, Math.max(0, progress));

  return (
    <section className="panel p-4 sm:p-5" aria-label="Level progress">
      <div className="flex items-baseline justify-between gap-3">
        <span className="font-pixel text-sm text-primary">LV {level}</span>
        <span className="font-mono text-sm text-muted tabular-nums">
          {totalXp.toLocaleString()} XP
        </span>
      </div>

      <div
        className="xp-track mt-3 h-4"
        role="progressbar"
        aria-valuenow={clamped}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`Level ${level} progress`}
      >
        <div className="xp-fill" style={{ width: `${clamped}%` }} />
      </div>

      <p className="mt-2 text-xs text-muted">
        {xpToNextLevel.toLocaleString()} XP to level {level + 1}
      </p>
    </section>
  );
}
