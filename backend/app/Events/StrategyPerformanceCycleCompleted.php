<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StrategyPerformanceCycleCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $packId,
        public readonly string $strategyClass,
        public readonly int $accountCount,
    ) {
    }
}
