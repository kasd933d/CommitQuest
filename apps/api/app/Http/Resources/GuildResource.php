<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuildResource extends JsonResource
{
    /**
     * The controller sets `member_count`, `is_member` and (for members)
     * `invite_code` attributes before serializing.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'owner_id' => $this->owner_id,
            'owner_username' => $this->whenLoaded('owner', fn () => $this->owner->username),
            'member_count' => (int) ($this->member_count ?? $this->members()->count()),
            'is_member' => (bool) ($this->is_member ?? false),
            // Never leaked to non-members (private guilds).
            'invite_code' => $this->when(
                (bool) ($this->is_member ?? false),
                fn () => $this->invite_code,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
