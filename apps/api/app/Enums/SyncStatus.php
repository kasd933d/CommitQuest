<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case NeedsReauth = 'needs_reauth';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Error => 'sync error',
            self::NeedsReauth => 'GitHub access revoked',
        };
    }
}
