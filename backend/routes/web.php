<?php

use App\Http\Controllers\SchedulerProbeController;
use Illuminate\Support\Facades\Route;

// Public liveness probe (no auth, no secrets): answers 503 once the cron
// scheduler has not been heard from. Watched from GitHub by
// .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

Route::get('/', function () {
    return view('welcome');
});
