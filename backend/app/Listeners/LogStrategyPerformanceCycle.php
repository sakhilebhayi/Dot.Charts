<?php

namespace App\Listeners;

use App\Events\StrategyPerformanceCycleCompleted;
use Illuminate\Support\Facades\Log;

class LogStrategyPerformanceCycle
{
    public function handle(StrategyPerformanceCycleCompleted $event): void
    {
        // Satisfies the ecosystem spec's "events emitted" naming
        // (trading.strategy.performance_cycle) without a message bus --
        // none exists elsewhere in this codebase. A future subscriber
        // can be registered against this same event without changing it.
        Log::info('trading.strategy.performance_cycle', [
            'pack_id' => $event->packId,
            'strategy_class' => $event->strategyClass,
            'account_count' => $event->accountCount,
        ]);
    }
}
