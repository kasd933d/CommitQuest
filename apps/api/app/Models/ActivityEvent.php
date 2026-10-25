<?php

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'github_event_id',
        'type',
        'repo_full_name',
        'occurred_at',
        'metadata',
        'xp_awarded',
        'awarded_at',
        'skipped_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'occurred_at' => 'datetime',
            'awarded_at' => 'datetime',
            'metadata' => 'array',
            'xp_awarded' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->awarded_at === null && $this->skipped_reason === null;
    }

    public function markAwarded(int $amount): void
    {
        $this->forceFill([
            'xp_awarded' => $amount,
            'awarded_at' => now('UTC'),
            'skipped_reason' => null,
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'xp_awarded' => 0,
            'awarded_at' => null,
            'skipped_reason' => $reason,
        ])->save();
    }

    public function message(): ?string
    {
        $message = $this->metadata['message'] ?? $this->metadata['title'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    public function url(): ?string
    {
        $url = $this->metadata['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
