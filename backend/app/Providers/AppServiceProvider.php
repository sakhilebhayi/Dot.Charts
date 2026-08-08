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
