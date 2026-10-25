<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'repo_full_name' => $this->repo_full_name,
            'message' => $this->message(),
            'url' => $this->url(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'xp_awarded' => $this->xp_awarded,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'skipped_reason' => $this->skipped_reason,
        ];
    }
}
