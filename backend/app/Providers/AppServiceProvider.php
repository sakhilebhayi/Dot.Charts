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
        // Global backstop for every /api/* route Laravel's minimal skeleton
        // doesn't throttle by default (bootstrap/app.php enables it via
        // throttleApi()) -- found during an audit that /me, /logout, and
        // the full /strategies and /journal-entries CRUD surfaces had zero
        // rate limiting at all. Keyed by IP only, not by resolved user:
        // this runs as part of the 'api' middleware group, before any
        // route-specific auth:sanctum middleware has resolved a user, so
        // $request->user() is unreliable here regardless of guard --
        // matches Laravel's own historical default limit (60/min). The
        // tighter, purpose-specific limiters below (backtests, auth-login,
        // etc.) still apply on top of this and remain the binding
        // constraint for the routes they cover.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Anonymous callers get a much tighter cap than authenticated users
        // on this endpoint specifically — it triggers a real, live
        // yfinance/ccxt call every time, so it's the platform's main
        // cost/abuse surface.
        RateLimiter::for('backtests', function (Request $request) {
            // $request->user() resolves via the default guard ('web',
            // session-based per config/auth.php) — it never inspects a
            // Bearer token on a route with no auth:sanctum middleware, so
            // it's always null here even for a real authenticated request.
            // Naming the 'sanctum' guard explicitly makes token resolution
            // work regardless of route middleware.
            $user = $request->user('sanctum');

            return $user
                ? Limit::perHour(30)->by('backtests:user:'.$user->id)
                : Limit::perHour(3)->by('backtests:ip:'.$request->ip());
        });

        // Unauthenticated by design (matches the endpoint's current
        // no-auth reality) — still bounded because the OCR shell-out to
        // tesseract is a real local resource cost.
        RateLimiter::for('chart-analysis', function (Request $request) {
            return Limit::perHour(10)->by('chart-analysis:ip:'.$request->ip());
        });

        // Keyed by email+IP, not IP alone: a per-IP-only limit would let one
        // slow attacker rotate the target email and keep guessing forever,
        // while a per-email-only limit would let a botnet lock a victim out
        // of their own account by deliberately tripping it from many IPs.
        // Tight (5/min) because this is the platform's primary credential-
        // stuffing / brute-force surface and a real login has no other cost
        // gate in front of it.
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by('auth-login:'.strtolower((string) $request->input('email')).':'.$request->ip());
        });

        // Per-IP only: registration has no existing-account identity to key
        // against, so this exists purely to slow down mass fake-account
        // creation from a single source, not to protect any one victim.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perHour(5)->by('auth-register:ip:'.$request->ip());
        });

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\StrategyPerformanceCycleCompleted::class,
            \App\Listeners\LogStrategyPerformanceCycle::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ComplianceGateRejected::class,
            \App\Listeners\LogComplianceGateRejection::class,
        );
    }
}
