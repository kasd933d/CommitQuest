<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    public const MAX_MEMBERS = 50;

    public const MAX_GUILDS_PER_USER = 3;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'owner_id',
        'is_public',
        'invite_code',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<GuildMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function isMember(int $userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }
}
