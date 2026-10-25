<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Levels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $level = (int) $this->level;
        $totalXp = (int) $this->total_xp;

        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'is_public' => (bool) $this->is_public,

            'total_xp' => $totalXp,
            'level' => $level,
            'level_progress' => Levels::progress($totalXp, $level),
            'xp_at_level_start' => Levels::threshold($level),
            'xp_at_next_level' => Levels::nextThreshold($level),
            'xp_to_next_level' => Levels::xpToNext($totalXp, $level),

            'current_streak' => (int) $this->current_streak,
            'longest_streak' => (int) $this->longest_streak,
            'streak_freezes' => (int) $this->streak_freezes,
            'last_active_date' => $this->last_active_date?->toDateString(),

            'sync_status' => $this->sync_status?->value,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),

            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
