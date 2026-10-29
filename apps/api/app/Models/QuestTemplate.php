<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestTemplate extends Model
{
    use HasFactory;

    public const PERIOD_DAILY = 'daily';

    public const PERIOD_WEEKLY = 'weekly';

    protected $fillable = [
        'key',
        'title',
        'description',
        'period',
        'metric',
        'target',
        'xp_reward',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'xp_reward' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<UserQuest, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserQuest::class);
    }
}
