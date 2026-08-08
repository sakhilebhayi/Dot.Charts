<?php

namespace App\Console\Commands;

use App\Services\ObservationPackGenerator;
use Illuminate\Console\Command;

class GenerateKnowledgePacks extends Command
{
    protected $signature = 'knowledge-packs:generate {strategy_class} {--period=}';

    protected $description = 'Generate a signed observation Knowledge Pack for a strategy class and period, if the aggregation floor is met.';

    public function handle(ObservationPackGenerator $generator): int
    {
        $strategyClass = $this->argument('strategy_class');
        $period = $this->option('period');

        $result = $generator->generateForPeriod($strategyClass, $period);

        if ($result['generated']) {
            $this->info("Generated pack {$result['pack']->pack_id} for {$strategyClass} ({$result['account_count']} accounts).");
        } elseif ($result['reason'] === 'below_floor') {
            $this->info("Skipped {$strategyClass}: below aggregation floor ({$result['account_count']} accounts).");
        } else {
            $this->info("Skipped {$strategyClass}: pack already generated for this period ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
