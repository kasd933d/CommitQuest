<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The signed-in user's profile, XP, level and streak.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Update settings (timezone, profile visibility).
     */
    public function update(UpdateMeRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user()->fresh());
    }

    /**
     * Hard delete the account and everything attached to it (PRD §4.1).
     * Child rows are removed by ON DELETE CASCADE foreign keys.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Tokens first: Sanctum's FK is not guaranteed to cascade on every driver.
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account and all data deleted.']);
    }
}
