<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserQuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->template->key,
            'title' => $this->template->title,
            'description' => $this->template->description,
            'period' => $this->template->period,
            'metric' => $this->template->metric,
            'icon' => $this->template->period === 'weekly' ? '👑' : '🗡️',
            'progress' => $this->progress,
            'target' => $this->target,
            'xp_reward' => (int) $this->template->xp_reward,
            'status' => $this->status,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
