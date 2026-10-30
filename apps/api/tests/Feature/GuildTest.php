<?php

namespace Tests\Feature;

use App\Models\Guild;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guild rules from PRD §4.7.
 */
class GuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_guild_makes_the_user_owner_and_first_member(): void
    {
        $user = User::factory()->create();

        $response = $this->withAuth($user)->postJson('/api/v1/guilds', [
            'name' => 'The Guild',
            'description' => 'A test guild',
            'is_public' => true,
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'The Guild')
            ->assertJsonPath('data.slug', 'the-guild')
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.is_member', true);

        // Invite code is shown to members.
        $this->assertNotNull($response->json('data.invite_code'));

        $guild = Guild::query()->where('slug', 'the-guild')->firstOrFail();
        $this->assertSame($user->id, $guild->owner_id);
        $this->assertTrue($guild->isMember($user->id));
    }

    public function test_creating_a_guild_unlocks_the_founder_badge(): void
    {
        $user = User::factory()->create();

        $this->withAuth($user)->postJson('/api/v1/guilds', ['name' => 'Founders'])->assertCreated();

        $this->assertContains('guild_founder', $user->fresh()->badges()->pluck('key')->all());
    }

    public function test_duplicate_guild_names_get_unique_slugs(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->withAuth($first)->postJson('/api/v1/guilds', ['name' => 'Dupes'])->assertCreated();
        $this->withAuth($second)->postJson('/api/v1/guilds', ['name' => 'Dupes'])->assertCreated();

        $this->assertSame(
            ['dupes', 'dupes-2'],
            Guild::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_a_user_belongs_to_at_most_three_guilds(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->withAuth($user)->postJson('/api/v1/guilds', ['name' => "Guild {$i}"])->assertCreated();
        }

        $this->withAuth($user)
            ->postJson('/api/v1/guilds', ['name' => 'Guild 4'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can belong to at most 3 guilds.');

        // Joining someone else's guild is blocked too.
        $other = User::factory()->create();
        $guild = $this->makeGuild($other, 'Open Guild', isPublic: true);

        $this->withAuth($user)
            ->postJson("/api/v1/guilds/{$guild->slug}/join")
            ->assertStatus(422);
    }

    public function test_public_guilds_can_be_joined_directly(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Public Guild', isPublic: true);

        $this->withAuth($joiner)
            ->postJson("/api/v1/guilds/{$guild->slug}/join")
            ->assertOk();

        $this->assertTrue($guild->fresh()->isMember($joiner->id));

        // Joining twice is a conflict, not a duplicate row.
        $this->withAuth($joiner)
            ->postJson("/api/v1/guilds/{$guild->slug}/join")
            ->assertStatus(409);

        $this->assertSame(2, $guild->members()->count());
    }

    public function test_private_guilds_require_the_invite_code(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Secret Guild', isPublic: false);

        $this->withAuth($joiner)
            ->postJson("/api/v1/guilds/{$guild->slug}/join", ['invite_code' => 'wrong-code'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That invite code is not valid.');

        $this->withAuth($joiner)
            ->postJson("/api/v1/guilds/{$guild->slug}/join", ['invite_code' => $guild->invite_code])
            ->assertOk();

        $this->assertTrue($guild->fresh()->isMember($joiner->id));
    }

    public function test_private_guilds_are_invisible_to_non_members(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Hidden Guild', isPublic: false);

        $this->withAuth($stranger)
            ->getJson("/api/v1/guilds/{$guild->slug}")
            ->assertNotFound();

        // And the invite code never leaks through the index.
        $json = $this->withAuth($stranger)->getJson('/api/v1/guilds')->assertOk()->json();
        $this->assertSame([], $json['discover']);
        $this->assertSame([], $json['mine']);
    }

    public function test_members_can_leave_but_owners_cannot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Leaving Guild');
        $this->withAuth($member)->postJson("/api/v1/guilds/{$guild->slug}/join")->assertOk();

        // The owner is protected.
        $this->withAuth($owner)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/leave")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Owners cannot leave their own guild. Delete it instead.');

        $this->withAuth($member)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/leave")
            ->assertOk();

        $this->assertFalse($guild->fresh()->isMember($member->id));
        $this->assertSame(1, $guild->members()->count());
    }

    public function test_only_the_owner_can_remove_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Kick Guild');

        $this->withAuth($member)->postJson("/api/v1/guilds/{$guild->slug}/join")->assertOk();

        $this->withAuth($other)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/members/{$member->id}")
            ->assertForbidden();

        $this->withAuth($member)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/members/{$member->id}")
            ->assertForbidden();

        $this->withAuth($owner)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/members/{$member->id}")
            ->assertOk();

        $this->assertFalse($guild->fresh()->isMember($member->id));
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Protected Owner');
        $this->withAuth($member)->postJson("/api/v1/guilds/{$guild->slug}/join")->assertOk();

        // A member cannot kick the owner (403 from the policy first).
        $this->withAuth($member)
            ->deleteJson("/api/v1/guilds/{$guild->slug}/members/{$owner->id}")
            ->assertForbidden();
    }

    public function test_only_the_owner_can_regenerate_the_invite_code(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Invite Guild', isPublic: false);
        $this->withAuth($member)->postJson("/api/v1/guilds/{$guild->slug}/join", ['invite_code' => $guild->invite_code])->assertOk();

        $old = $guild->invite_code;

        $this->withAuth($member)
            ->postJson("/api/v1/guilds/{$guild->slug}/invite/regenerate")
            ->assertForbidden();

        $new = $this->withAuth($owner)
            ->postJson("/api/v1/guilds/{$guild->slug}/invite/regenerate")
            ->assertOk()
            ->json('invite_code');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $guild->fresh()->invite_code);

        // The old code no longer works.
        $joiner = User::factory()->create();
        $this->withAuth($joiner)
            ->postJson("/api/v1/guilds/{$guild->slug}/join", ['invite_code' => $old])
            ->assertStatus(422);
    }

    public function test_the_weekly_leaderboard_ranks_by_this_weeks_xp(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Board Guild');
        $this->withAuth($member)->postJson("/api/v1/guilds/{$guild->slug}/join")->assertOk();

        $this->giveXp($member, 100);
        $this->giveXp($owner, 300);
        $this->giveXp($member, 50);
        // XP from last week must not count.
        $this->giveXp($member, 999, now('UTC')->subWeek());

        $board = $this->withAuth($owner)
            ->getJson("/api/v1/guilds/{$guild->slug}")
            ->assertOk()
            ->json('leaderboard');

        $this->assertCount(2, $board);
        $this->assertSame($owner->id, $board[0]['user_id']);
        $this->assertSame(300, $board[0]['weekly_xp']);
        $this->assertSame(1, $board[0]['rank']);
        $this->assertSame($member->id, $board[1]['user_id']);
        $this->assertSame(150, $board[1]['weekly_xp']);
        $this->assertSame(2, $board[1]['rank']);
    }

    public function test_joining_a_guild_notifies_the_owner(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Notify Guild');

        $this->withAuth($joiner)->postJson("/api/v1/guilds/{$guild->slug}/join")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'guild_invite',
        ]);
    }

    public function test_the_invite_code_is_not_leaked_to_non_members(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $guild = $this->makeGuild($owner, 'Private Things', isPublic: false);

        // The owner sees it...
        $mine = $this->withAuth($owner)->getJson('/api/v1/guilds')->json('mine.0');
        $this->assertSame($guild->invite_code, $mine['invite_code']);

        // ...nobody else does.
        $this->withAuth($joiner)->postJson("/api/v1/guilds/{$guild->slug}/join", ['invite_code' => $guild->invite_code])->assertOk();
        $joined = $this->withAuth($joiner)->getJson('/api/v1/guilds')->json('mine.0');
        $this->assertSame($guild->invite_code, $joined['invite_code'], 'members may share the code');
    }

    public function test_guilds_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/guilds')->assertUnauthorized();
        $this->postJson('/api/v1/guilds', ['name' => 'x'])->assertUnauthorized();

        // A browser hitting the API directly sends no JSON Accept header;
        // it must still get 401 JSON (not the missing `route('login')`
        // redirect fallback, which used to 500).
        $this->get('/api/v1/guilds')->assertUnauthorized();
        $this->get('/api/v1/guilds/code-crafters')->assertUnauthorized();
    }

    private function makeGuild(User $owner, string $name, bool $isPublic = true): Guild
    {
        return app(GuildService::class)->create($owner, $name, null, $isPublic);
    }

    private function giveXp(User $user, int $amount, $at = null): void
    {
        // Unique source_id per row: the ledger has a unique
        // (user_id, source_type, source_id) index.
        static $sequence = 0;

        XpLedger::query()->forceCreate([
            'user_id' => $user->id,
            'source_type' => XpLedger::SOURCE_ACTIVITY,
            'source_id' => ++$sequence,
            'amount' => $amount,
            'reason' => 'test',
            'created_at' => $at ?? now('UTC'),
            'updated_at' => $at ?? now('UTC'),
        ]);
    }

    private function withAuth(User $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('web')->plainTextToken]);
    }
}
