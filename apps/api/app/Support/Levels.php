<?php

namespace App\Support;

/**
 * Level curve for CommitQuest (PRD §4.3).
 *
 *   level = floor(sqrt(total_xp / 100)) + 1
 *   xp required to *be* level L = 100 * (L - 1)^2
 */
final class Levels
{
    public const BASE = 100;

    /**
     * Total XP required to reach the given level.
     */
    public static function threshold(int $level): int
    {
        return self::BASE * (($level - 1) ** 2);
    }

    /**
     * Derive a level from a total XP amount.
     */
    public static function fromXp(int $totalXp): int
    {
        return (int) floor(sqrt(max(0, $totalXp) / self::BASE)) + 1;
    }

    /**
     * Total XP required for the next level.
     */
    public static function nextThreshold(int $level): int
    {
        return self::threshold($level + 1);
    }

    /**
     * XP still needed to reach the next level.
     */
    public static function xpToNext(int $totalXp, int $level): int
    {
        return max(0, self::nextThreshold($level) - $totalXp);
    }

    /**
     * Progress through the current level, 0–100.
     */
    public static function progress(int $totalXp, int $level): int
    {
        $floor = self::threshold($level);
        $ceiling = self::nextThreshold($level);
        $span = $ceiling - $floor;

        if ($span <= 0) {
            return 100;
        }

        return (int) min(100, max(0, round((($totalXp - $floor) / $span) * 100)));
    }
}
