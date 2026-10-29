<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes (prefix: /api/v1)
|--------------------------------------------------------------------------
| Authenticated routes use Sanctum personal access tokens. The Next.js app
| holds the token in an httpOnly cookie and forwards it as `Authorization:
| Bearer {token}` through its route-handler proxy.
*/

Route::prefix('v1')->group(function () {

    // ---- Authentication ---------------------------------------------------
    Route::prefix('auth')->middleware('throttle:public')->group(function () {
        Route::get('/github/redirect', [AuthController::class, 'redirect']);
        Route::get('/github/callback', [AuthController::class, 'callback']);
    });

    Route::middleware('throttle:api')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

        // ---- Me ------------------------------------------------------------
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [MeController::class, 'show']);
            Route::patch('/me', [MeController::class, 'update']);
            Route::delete('/me', [MeController::class, 'destroy']);

            // ---- Activity & sync (M2) ---------------------------------------
            Route::get('/me/activity', [MeController::class, 'activity']);
            Route::post('/me/sync', [MeController::class, 'sync']);

            // ---- Streaks, quests, badges, notifications (M3) ---------------
            Route::get('/me/heatmap', [MeController::class, 'heatmap']);
            Route::get('/me/quests', [MeController::class, 'quests']);
            Route::get('/me/badges', [MeController::class, 'badges']);
            Route::get('/me/notifications', [MeController::class, 'notifications']);
            Route::post('/me/notifications/read', [MeController::class, 'readNotifications']);

            // ---- Guilds (M4, PRD §4.7) ------------------------------------
            Route::get('/guilds', [GuildController::class, 'index']);
            Route::post('/guilds', [GuildController::class, 'store']);
            Route::get('/guilds/{slug}', [GuildController::class, 'show']);
            Route::post('/guilds/{slug}/join', [GuildController::class, 'join']);
            Route::delete('/guilds/{slug}/leave', [GuildController::class, 'leave']);
            Route::delete('/guilds/{slug}/members/{userId}', [GuildController::class, 'removeMember']);
            Route::post('/guilds/{slug}/invite/regenerate', [GuildController::class, 'regenerateInvite']);
        });
    });

    // ---- Public endpoints (M4): throttled per IP (PRD §9) ----------------
    Route::middleware('throttle:public')->group(function () {
        Route::get('/users/{username}', [PublicController::class, 'profile']);
        Route::get('/leaderboard', [PublicController::class, 'leaderboard']);
        Route::get('/stats', [PublicController::class, 'stats']);
    });
});
