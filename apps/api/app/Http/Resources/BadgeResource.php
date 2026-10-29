<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BadgeResource extends JsonResource
{
    /**
     * The controller sets `is_unlocked` / `unlocked_at` attributes on every
     * badge (a user needs to see the locked ones too, PRD §4.6).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unlockedAt = $this->unlocked_at ?? null;

        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'rarity' => $this->rarity,
            'unlocked' => (bool) ($this->is_unlocked ?? false),
            'unlocked_at' => $unlockedAt !== null
                ? CarbonImmutable::parse($unlockedAt)->toIso8601String()
                : null,
        ];
    }
}
