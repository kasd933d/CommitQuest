<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
        'rarity',
    ];

    /**
     * @return HasMany<UserBadge, $this>
     */
    public function unlocks(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }
}
