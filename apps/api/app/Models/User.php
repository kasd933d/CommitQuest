<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'github_id',
        'username',
        'display_name',
        'avatar_url',
        'github_token',
        'timezone',
        'is_public',
        'total_xp',
        'level',
        'current_streak',
        'longest_streak',
        'last_active_date',
        'streak_freezes',
        'events_etag',
        'last_synced_at',
        'sync_status',
        'sync_error',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'github_token',
        'events_etag',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_token' => 'encrypted',
            'is_public' => 'boolean',
            'total_xp' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'streak_freezes' => 'integer',
            'last_active_date' => 'date:Y-m-d',
            'last_synced_at' => 'datetime',
            'sync_status' => SyncStatus::class,
        ];
    }

    /**
     * @return HasMany<ActivityEvent, $this>
     */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /**
     * @return HasMany<XpLedger, $this>
     */
    public function xpLedger(): HasMany
    {
        return $this->hasMany(XpLedger::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
