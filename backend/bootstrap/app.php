<?php

use App\Http\Middleware\SecurityHeaders;
use App\Services\Guardian\ErrorCounter;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every Laravel response, including /up, /api and error pages: baseline security headers.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'operator' => \App\Http\Middleware\EnsurePlatformOperator::class,
        ]);
        // Laravel 11/12's minimal skeleton doesn't apply throttling to the
        // 'api' middleware group by default, unlike older Laravel versions
        // -- found during an audit that every route without its own
        // explicit throttle:X (/me, /logout, /strategies CRUD,
        // /journal-entries CRUD) had zero rate limiting at all. This is a
        // backstop, not a replacement for the tighter per-endpoint limits
        // already in place (backtests, chart-analysis, options-vol,
        // auth-login, auth-register) -- Laravel enforces all matching throttle
        // middleware on a route, so those keep their own, stricter limits.
        $middleware->throttleApi();

        // Charts has no `login` route (the frontend is static pages that call
        // the token API), so Laravel's default guest redirect, route('login'),
        // threw RouteNotFoundException and an unauthenticated request without
        // `Accept: application/json` answered 500 instead of 401. JSON is now
        // forced for /api/* below; anything else is sent home.
        $middleware->redirectGuestsTo(fn (Request $request) => '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the guardian's hourly error counter (ErrorRateCheck). The
        // counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (\Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });

        // Every /api/* response is JSON whatever the client sent. Without
        // this an unauthenticated request lacking `Accept: application/json`
        // was redirected (302) to the home page instead of getting a 401 --
        // visible in the deploy workflow's own smoke check ("/api POST
        // backtests: 302"). Dot.Memory, Dot.Dopemine and Dot.Central do the
        // same.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
