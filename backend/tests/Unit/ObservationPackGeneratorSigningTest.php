<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationPackGeneratorSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dkp.signing_key' => 'test-signing-key']);
    }

    private function seedEligibleMonth(string $strategy, string $period): void
    {
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $run = BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => $strategy,
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => ['metrics' => [
                    'total_return_pct' => 5.0,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => -3.0,
                    'trade_count' => 12,
                    'losing_trade_count' => 5,
                ]],
            ]);
            $run->created_at = \Carbon\Carbon::parse($period . '-05');
            $run->save();
        }
    }

    public function test_generate_for_period_persists_a_signed_pack(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertSame('observation', $pack->payload_type);
        $this->assertSame('v1', $pack->signing_key_version);
        $this->assertMatchesRegularExpression('/^dkp:charts:obs:2026-08-01:\d{4}$/', $pack->pack_id);
        $this->assertNotEmpty($pack->signature);
    }

    public function test_signature_verifies_against_canonical_payload_and_fails_on_tamper(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $generator = new ObservationPackGenerator();
        $pack = $generator->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertTrue($generator->verify($pack));

        $pack->payload = array_merge($pack->payload, ['mean_return_pct' => 999.0]);
        $this->assertFalse($generator->verify($pack));
    }

    public function test_regenerating_the_same_strategy_and_period_does_not_duplicate(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $generator = new ObservationPackGenerator();

        $first = $generator->generateForPeriod('ma_crossover', '2026-08');
        $second = $generator->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($first['generated']);
        $this->assertFalse($second['generated']);
        $this->assertSame('already_generated', $second['reason']);
        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_below_floor_period_reports_reason_without_persisting(): void
    {
        // Only 10 users -- below the floor.
        for ($i = 0; $i < 10; $i++) {
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

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertFalse($result['generated']);
        $this->assertSame('below_floor', $result['reason']);
        $this->assertSame(10, $result['account_count']);
        $this->assertSame(0, KnowledgePack::count());
    }
}
