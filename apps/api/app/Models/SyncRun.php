<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'finished_at',
        'events_fetched',
        'events_new',
        'status',
        'error',
        'rate_limit_remaining',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'events_fetched' => 'integer',
            'events_new' => 'integer',
            'rate_limit_remaining' => 'integer',
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
