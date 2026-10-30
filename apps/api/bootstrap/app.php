<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The API is consumed by the Next.js proxy (server to server) and
        // optionally by browsers on the web origin only (see config/cors.php).
        $middleware->trustProxies(at: '*');

        // There is no session `login` route (the default callback would
        // throw RouteNotFoundException and surface as a 500). Unauthenticated
        // requests get a 401 JSON body via shouldRenderJsonWhen below.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API-only backend: there is no session "login" page, so
        // AuthenticationException's redirect fallback would blow up on
        // `route('login')`. Always render JSON errors on /api/*, so an
        // unauthenticated hit returns 401 JSON even without an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $_e) => $request->is('api/*'),
        );
    })->create();
