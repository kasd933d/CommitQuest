<?php

namespace App\Http\Controllers\Api;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Cache prefix for OAuth state values (CSRF protection, PRD §11).
     */
    private const STATE_PREFIX = 'github:oauth:state:';

    /**
     * Return the GitHub authorize URL the client should redirect the user to.
     *
     * A single-use `state` is generated server side and validated on callback,
     * so a login can not be completed with a code forged by a third party.
     */
    public function redirect(): JsonResponse
    {
        $state = Str::random(40);
        Cache::put(self::STATE_PREFIX.$state, 1, now()->addMinutes(10));

        $url = Socialite::driver('github')->stateless()->redirect()->getTargetUrl();
        $separator = str_contains($url, '?') ? '&' : '?';

        return response()->json([
            'url' => $url.$separator.'state='.urlencode($state),
            'expires_in' => 600,
        ]);
    }

    /**
     * Exchange the GitHub code for a user and an API token.
     *
     * The token is returned to the Next.js route handler only, which stores it
     * in an httpOnly cookie - it is never exposed to browser JavaScript.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string', 'max:64'],
        ]);

        if (! Cache::pull(self::STATE_PREFIX.$request->string('state'))) {
            return response()->json([
                'message' => 'Invalid or expired sign-in state. Please try again.',
            ], 422);
        }

        $github = Socialite::driver('github')->stateless()->user();

        $user = User::updateOrCreate(
            ['github_id' => $github->getId()],
            [
                'username' => $github->getNickname(),
                'display_name' => $github->getName() ?: $github->getNickname(),
                'avatar_url' => $github->getAvatar(),
                'github_token' => $github->token,
                'sync_status' => SyncStatus::Ok,
                'sync_error' => null,
            ],
        )->refresh(); // reload DB defaults (timezone, is_public, level) into the model

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'first_login' => $user->wasRecentlyCreated,
        ]);
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
