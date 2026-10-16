<?php

use App\Http\Controllers\SchedulerProbeController;
use App\Services\ObservationPackGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    foreach (ObservationPackGenerator::knownStrategyClasses() as $strategyClass) {
        Artisan::call('knowledge-packs:generate', ['strategy_class' => $strategyClass]);
    }
})->monthlyOn(1, '01:00')->description('knowledge-packs-monthly-cycle');

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes. Same
// shape as Dot.Central's probe so every Dot platform is watched alike.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');
