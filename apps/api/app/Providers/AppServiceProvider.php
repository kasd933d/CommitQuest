<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // PRD §9: 60 req/min per authenticated user (falls back to the IP for
        // anonymous callers), 30 req/min per IP on public endpoints.
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user('sanctum')?->getAuthIdentifier() ?? 'ip:'.$request->ip();

            return Limit::perMinute(60)->by($key)->response(fn () => response()->json([
                'message' => 'Too many requests, slow down.',
            ], 429));
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(30)->by('ip:'.$request->ip())->response(fn () => response()->json([
                'message' => 'Too many requests, slow down.',
            ], 429));
        });
    }
}
