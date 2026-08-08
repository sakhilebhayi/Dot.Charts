<?php

namespace Tests\Unit;

use App\Events\StrategyPerformanceCycleCompleted;
use App\Models\BacktestRun;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class StrategyPerformanceCycleEventTest extends TestCase
{
    use RefreshDatabase;
    use UsesDkpTestKey;

    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }

    public function test_generating_a_pack_dispatches_the_performance_cycle_event(): void
    {
        $this->setUpDkpTestKey();
        Event::fake();

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $run = BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => ['metrics' => ['total_return_pct' => 5.0, 'win_rate_pct' => 55.0, 'max_drawdown_pct' => -3.0, 'trade_count' => 12, 'losing_trade_count' => 5]],
            ]);
            $run->created_at = \Carbon\Carbon::parse('2026-08-05');
            $run->save();
        }

        (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        Event::assertDispatched(StrategyPerformanceCycleCompleted::class, function ($event) {
            return $event->strategyClass === 'ma_crossover' && $event->accountCount === 50;
        });
    }

    public function test_below_floor_does_not_dispatch_the_event(): void
    {
        $this->setUpDkpTestKey();
        Event::fake();

        $user = User::factory()->create();
        $run = BacktestRun::create([
            'user_id' => $user->id,
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => [],
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'status' => 'complete',
            'results' => ['metrics' => ['total_return_pct' => 5.0, 'win_rate_pct' => 55.0, 'max_drawdown_pct' => -3.0, 'trade_count' => 12, 'losing_trade_count' => 5]],
        ]);
        $run->created_at = \Carbon\Carbon::parse('2026-08-05');
        $run->save();

        (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        Event::assertNotDispatched(StrategyPerformanceCycleCompleted::class);
    }
}
