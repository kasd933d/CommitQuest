<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\JoinGuildRequest;
use App\Http\Requests\StoreGuildRequest;
use App\Http\Resources\GuildResource;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuildController extends Controller
{
    /**
     * The user's guilds plus public guilds they could join (PRD §4.7).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $mine = Guild::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->with('owner')
            ->withCount('members')
            ->get()
            ->each(fn (Guild $guild) => $guild->setAttribute('is_member', true));

        $discover = Guild::query()
            ->where('is_public', true)
            ->whereDoesntHave('members', fn ($query) => $query->where('user_id', $user->id))
            ->with('owner')
            ->withCount('members')
            ->orderByDesc('members_count')
            ->limit(50)
            ->get()
            ->each(fn (Guild $guild) => $guild->setAttribute('is_member', false));

        return response()->json([
            'mine' => GuildResource::collection($mine)->resolve(),
            'discover' => GuildResource::collection($discover)->resolve(),
        ]);
    }

    public function store(StoreGuildRequest $request, GuildService $guilds): JsonResponse
    {
        try {
            $guild = $guilds->create(
                $request->user(),
                $request->validated('name'),
                $request->validated('description'),
                (bool) $request->input('is_public', true),
            );
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $guild->load('owner')->loadCount('members');
        $guild->setAttribute('is_member', true);

        return (new GuildResource($guild))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Guild detail plus the weekly leaderboard (PRD §4.7). Public guilds are
     * readable by anyone; private ones only by members.
     */
    public function show(Request $request, string $slug, GuildService $guilds): JsonResponse
    {
        $guild = Guild::query()->where('slug', $slug)->with('owner')->withCount('members')->first();

        if ($guild === null) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        $user = $request->user();
        $isMember = $guild->isMember($user->id);

        if (! $guild->is_public && ! $isMember) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        $guild->setAttribute('is_member', $isMember);

        return response()->json([
            'guild' => (new GuildResource($guild))->resolve($request),
            'leaderboard' => $guilds->weeklyLeaderboard($guild),
        ]);
    }

    public function join(Request $request, JoinGuildRequest $joinRequest, string $slug, GuildService $guilds): JsonResponse
    {
        $guild = Guild::query()->where('slug', $slug)->first();

        if ($guild === null) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        if ($guild->isMember($request->user()->id)) {
            return response()->json(['message' => 'You are already a member.'], 409);
        }

        try {
            $guilds->join($request->user(), $guild, $joinRequest->validated('invite_code'));
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => "Joined {$guild->name}."]);
    }

    public function leave(Request $request, string $slug, GuildService $guilds): JsonResponse
    {
        $guild = Guild::query()->where('slug', $slug)->first();

        if ($guild === null || ! $guild->isMember($request->user()->id)) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        try {
            $guilds->leave($request->user(), $guild);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => "Left {$guild->name}."]);
    }

    /**
     * Owner-only: remove a member (PRD §4.7).
     */
    public function removeMember(Request $request, string $slug, int $userId, GuildService $guilds): JsonResponse
    {
        $guild = Guild::query()->where('slug', $slug)->first();

        if ($guild === null) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        $this->authorize('removeMember', [$guild, $userId]);

        if ($userId === $guild->owner_id) {
            return response()->json(['message' => 'The owner cannot be removed.'], 422);
        }

        if (! $guild->isMember($userId)) {
            return response()->json(['message' => 'That user is not a member.'], 404);
        }

        $guilds->removeMember($guild, $userId);

        return response()->json(['message' => 'Member removed.']);
    }

    /**
     * Owner-only: regenerate the invite code (PRD §4.7).
     */
    public function regenerateInvite(Request $request, string $slug, GuildService $guilds): JsonResponse
    {
        $guild = Guild::query()->where('slug', $slug)->first();

        if ($guild === null) {
            return response()->json(['message' => 'Guild not found.'], 404);
        }

        $this->authorize('regenerateInvite', $guild);

        return response()->json(['invite_code' => $guilds->regenerateInvite($guild)]);
    }
}
