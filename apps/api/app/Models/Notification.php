<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const TYPE_LEVEL_UP = 'level_up';

    public const TYPE_QUEST_COMPLETE = 'quest_complete';

    public const TYPE_BADGE_UNLOCKED = 'badge_unlocked';

    public const TYPE_STREAK_AT_RISK = 'streak_at_risk';

    public const TYPE_GUILD_INVITE = 'guild_invite';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
