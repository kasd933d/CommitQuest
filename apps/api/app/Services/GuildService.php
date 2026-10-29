<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Notification;
use App\Models\User;
use App\Models\XpLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guilds (PRD §4.7):
 *  - a user belongs to at most 3 guilds, a guild has at most 50 members,
 *  - public guilds can be joined directly, private ones need the invite code,
 *  - roles are `owner` (creator, can remove members and regenerate the code)
 *    and `member`,
 *  - the weekly leaderboard ranks members by XP earned in the current UTC
 *    week (Monday 00:00 → Sunday 23:59), matching the PRD's definition.
 */
class GuildService
{
    public function __construct(
        private readonly BadgeService $badges,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Create a guild owned by the user. The owner becomes the first member.
     */
    public function create(User $user, string $name, ?string $description, bool $isPublic): Guild
    {
        $guild = DB::transaction(function () use ($user, $name, $description, $isPublic): Guild {
            $existing = GuildMember::query()->where('user_id', $user->id)->lockForUpdate()->count();

            if ($existing >= Guild::MAX_GUILDS_PER_USER) {
                throw new \DomainException('You can belong to at most '.Guild::MAX_GUILDS_PER_USER.' guilds.');
            }

            $guild = Guild::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
                'owner_id' => $user->id,
                'is_public' => $isPublic,
                'invite_code' => $this->newInviteCode(),
            ]);

            GuildMember::query()->create([
                'guild_id' => $guild->id,
                'user_id' => $user->id,
                'role' => GuildMember::ROLE_OWNER,
                'joined_at' => now('UTC'),
            ]);

            return $guild;
        });

        // Guild Founder (PRD §4.6) — evaluated right after creation.
        $this->badges->check($user->fresh());

        return $guild;
    }

    /**
     * Join a guild: public guilds need nothing, private ones must match the
     * invite code. Returns false when the user is already a member.
     *
     * @throws \DomainException when the guild is full or the code is wrong
     */
    public function join(User $user, Guild $guild, ?string $inviteCode): bool
    {
        return DB::transaction(function () use ($user, $guild, $inviteCode): bool {
            $locked = Guild::query()->whereKey($guild->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_public && ! hash_equals($locked->invite_code, (string) $inviteCode)) {
                throw new \DomainException('That invite code is not valid.');
            }

            $memberships = GuildMember::query()->where('user_id', $user->id)->count();

            if ($memberships >= Guild::MAX_GUILDS_PER_USER) {
                throw new \DomainException('You can belong to at most '.Guild::MAX_GUILDS_PER_USER.' guilds.');
            }

            $inserted = GuildMember::query()->insertOrIgnore([
                'guild_id' => $locked->id,
                'user_id' => $user->id,
                'role' => GuildMember::ROLE_MEMBER,
                'joined_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            if ($inserted === 0) {
                return false; // already a member
            }

            $memberCount = $locked->members()->count();

            if ($memberCount > Guild::MAX_MEMBERS) {
                // The row count check happens under the guild lock, so this
                // can only trigger from a race we roll back.
                GuildMember::query()->where('guild_id', $locked->id)->where('user_id', $user->id)->delete();

                throw new \DomainException('That guild is full.');
            }

            if ($locked->owner_id !== $user->id) {
                $this->notifications->push(
                    $locked->owner,
                    Notification::TYPE_GUILD_INVITE,
                    "{$user->username} joined {$locked->name}",
                    'A new member joined your guild.',
                    ['guild_slug' => $locked->slug, 'user_id' => $user->id],
                );
            }

            return true;
        });
    }

    /**
     * Leave a guild. The owner can only leave by deleting the guild, which
     * keeps the "every guild has an owner" invariant simple.
     *
     * @throws \DomainException
     */
    public function leave(User $user, Guild $guild): void
    {
        if ($guild->owner_id === $user->id) {
            throw new \DomainException('Owners cannot leave their own guild. Delete it instead.');
        }

        GuildMember::query()->where('guild_id', $guild->id)->where('user_id', $user->id)->delete();
    }

    /**
     * Owner-only: remove a member.
     */
    public function removeMember(Guild $guild, int $userId): void
    {
        GuildMember::query()->where('guild_id', $guild->id)->where('user_id', $userId)->delete();
    }

    /**
     * Owner-only: regenerate the invite code (old code stops working).
     */
    public function regenerateInvite(Guild $guild): string
    {
        $code = $this->newInviteCode();

        $guild->forceFill(['invite_code' => $code])->save();

        return $code;
    }

    /**
     * Weekly XP per member of a guild (Monday 00:00 UTC → now), ranked.
     *
     * @return list<array{user_id: int, username: string, display_name: string|null, avatar_url: string|null, weekly_xp: int, rank: int}>
     */
    public function weeklyLeaderboard(Guild $guild): array
    {
        $weekStart = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);

        $rows = XpLedger::query()
            ->select('xp_ledger.user_id')
            ->selectRaw('SUM(xp_ledger.amount) AS weekly_xp')
            ->join('guild_members', 'guild_members.user_id', '=', 'xp_ledger.user_id')
            ->where('guild_members.guild_id', $guild->id)
            ->where('xp_ledger.created_at', '>=', $weekStart)
            ->groupBy('xp_ledger.user_id')
            ->orderByDesc('weekly_xp')
            ->orderBy('xp_ledger.user_id')
            ->get();

        $members = $guild->members()->with('user')->get()->keyBy('user_id');

        $ranked = [];
        $rank = 0;
        $seen = [];

        foreach ($rows as $row) {
            $member = $members->get((int) $row->user_id);

            if ($member === null) {
                continue; // left the guild after earning
            }

            $rank++;
            $seen[] = (int) $row->user_id;
            $ranked[] = [
                'user_id' => (int) $row->user_id,
                'username' => $member->user->username,
                'display_name' => $member->user->display_name,
                'avatar_url' => $member->user->avatar_url,
                'weekly_xp' => (int) $row->weekly_xp,
                'rank' => $rank,
            ];
        }

        // Members with no XP this week still appear, ranked last.
        foreach ($members as $userId => $member) {
            if (in_array((int) $userId, $seen, true)) {
                continue;
            }

            $rank++;
            $ranked[] = [
                'user_id' => (int) $userId,
                'username' => $member->user->username,
                'display_name' => $member->user->display_name,
                'avatar_url' => $member->user->avatar_url,
                'weekly_xp' => 0,
                'rank' => $rank,
            ];
        }

        return $ranked;
    }

    /**
     * Global leaderboards (PRD §4.7): top 100 by weekly XP and by total XP.
     *
     * @return list<array{user_id: int, username: string, display_name: string|null, avatar_url: string|null, xp: int, level: int, rank: int}>
     */
    public function globalLeaderboard(string $scope): array
    {
        if ($scope === 'weekly') {
            $weekStart = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);

            $rows = XpLedger::query()
                ->select('user_id')
                ->selectRaw('SUM(amount) AS xp')
                ->where('created_at', '>=', $weekStart)
                ->groupBy('user_id')
                ->orderByDesc('xp')
                ->orderBy('user_id')
                ->limit(100)
                ->get();

            $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

            return $this->rank($rows->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'username' => $users[$row->user_id]->username,
                'display_name' => $users[$row->user_id]->display_name,
                'avatar_url' => $users[$row->user_id]->avatar_url,
                'xp' => (int) $row->xp,
                'level' => $users[$row->user_id]->level,
            ])->all());
        }

        $users = User::query()
            ->where('is_public', true)
            ->orderByDesc('total_xp')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return $this->rank($users->map(fn (User $user) => [
            'user_id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
            'xp' => $user->total_xp,
            'level' => $user->level,
        ])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'guild';
        }

        $slug = $base;
        $suffix = 2;

        while (Guild::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function newInviteCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (Guild::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
