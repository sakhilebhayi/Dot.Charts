<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
