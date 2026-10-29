<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * In-app notifications (PRD §4.9): level ups, quest completions, badge
 * unlocks, streak-at-risk warnings and guild invites. The bell polls
 * `GET /me/notifications` every 60 seconds.
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function push(User $user, string $type, string $title, string $body, array $data = []): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data === [] ? null : $data,
        ]);
    }

    public function levelUp(User $user, int $from, int $to): Notification
    {
        return $this->push(
            $user,
            Notification::TYPE_LEVEL_UP,
            "Level up! You reached level {$to}",
            "You went from level {$from} to level {$to}.",
            ['from' => $from, 'to' => $to],
        );
    }

    public function paginate(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->notifications()
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return $user->notifications()->whereNull('read_at')->count();
    }

    /**
     * Mark the given ids (or every unread notification) as read.
     *
     * @param  list<int>|null  $ids
     */
    public function markRead(User $user, ?array $ids): int
    {
        $query = $user->notifications()->whereNull('read_at');

        if ($ids !== null && $ids !== []) {
            $query->whereIn('id', $ids);
        }

        $query->update(['read_at' => now('UTC')]);

        return $this->unreadCount($user);
    }
}
