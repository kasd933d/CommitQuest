<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XpLedger extends Model
{
    use HasFactory;

    /** The table name is `xp_ledger` (singular), as defined in the migration. */
    protected $table = 'xp_ledger';

    public const SOURCE_ACTIVITY = 'activity';

    public const SOURCE_QUEST = 'quest';

    public const SOURCE_BADGE = 'badge';

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'source_id' => 'integer',
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
