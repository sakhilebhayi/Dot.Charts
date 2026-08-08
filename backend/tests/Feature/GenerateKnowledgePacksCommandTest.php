<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateKnowledgePacksCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesDkpTestKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDkpTestKey();
    }

    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }

    public function test_command_generates_a_pack_for_an_eligible_strategy_and_period(): void
    {
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

        $this->artisan('knowledge-packs:generate', ['strategy_class' => 'ma_crossover', '--period' => '2026-08'])
            ->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_command_reports_below_floor_without_failing(): void
    {
        $this->artisan('knowledge-packs:generate', ['strategy_class' => 'ma_crossover', '--period' => '2026-08'])
            ->assertSuccessful();

        $this->assertSame(0, KnowledgePack::count());
    }

    public function test_scheduler_registers_the_monthly_call(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $this->assertTrue(
            $events->contains(fn ($event) => str_contains($event->command ?? '', 'knowledge-packs:generate') || $event->description === 'knowledge-packs-monthly-cycle')
        );
    }
}
