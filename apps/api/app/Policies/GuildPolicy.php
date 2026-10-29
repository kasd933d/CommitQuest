<?php

namespace App\Policies;

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\User;

/**
 * Authorization for guild management (PRD §4.7): owners can remove members,
 * regenerate the invite code and delete the guild; plain members can read and
 * leave; the public detail page is readable by anyone for public guilds.
 */
class GuildPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Guild $guild): bool
    {
        return $guild->is_public || $guild->isMember($user->id);
    }

    public function update(User $user, Guild $guild): bool
    {
        return $guild->owner_id === $user->id;
    }

    public function removeMember(User $user, Guild $guild, int $userId): bool
    {
        if ($guild->owner_id !== $user->id) {
            return false;
        }

        // The owner is protected: leaving the guild is not a removal path.
        return $userId !== $guild->owner_id;
    }

    public function regenerateInvite(User $user, Guild $guild): bool
    {
        return $guild->owner_id === $user->id;
    }

    public function delete(User $user, Guild $guild): bool
    {
        return $guild->owner_id === $user->id;
    }

    public function join(User $user, Guild $guild): bool
    {
        if ($guild->isMember($user->id)) {
            return false;
        }

        $memberships = GuildMember::query()->where('user_id', $user->id)->count();

        return $memberships < Guild::MAX_GUILDS_PER_USER;
    }
}
