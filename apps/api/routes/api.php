<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
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
        });
    });
});
