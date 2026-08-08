<?php

use App\Services\ObservationPackGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    foreach (ObservationPackGenerator::knownStrategyClasses() as $strategyClass) {
        Artisan::call('knowledge-packs:generate', ['strategy_class' => $strategyClass]);
    }
})->monthlyOn(1, '01:00')->description('knowledge-packs-monthly-cycle');
