<?php

use App\Http\Controllers\CspReportController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Middleware\AuthenticateGuardian;
use Illuminate\Support\Facades\Route;

// Public liveness probe (no auth, no secrets): answers 503 once the cron
// scheduler has not been heard from. Watched from GitHub by
// .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// internals, so bearer-authenticated with a dedicated token (503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');

// Content-Security-Policy violation reports. The browser posts these itself, so there is no form, no session and no
// CSRF token: the whole web group (session, cookies, CSRF) is dropped and the route is throttled instead. In
// production the docroot shim hands this path to Laravel like any other that is not a static file.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware('web')
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

Route::get('/', function () {
    return view('welcome');
});
