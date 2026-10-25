<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Services\XpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessActivityEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(XpService $xp): void
    {
        $event = ActivityEvent::query()->find($this->eventId);

        if ($event === null || $event->awarded_at !== null) {
            return;
        }

        $xp->award($event);
    }
}
