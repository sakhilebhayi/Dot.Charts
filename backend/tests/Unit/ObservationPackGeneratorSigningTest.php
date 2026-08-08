<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class ObservationPackGeneratorSigningTest extends TestCase
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

    public function test_generate_for_period_persists_a_signed_real_envelope(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
        $this->assertSame('metric', $pack->payload_type);
        $this->assertCount(4, $pack->envelope['payloads']);
        $this->assertNotEmpty($pack->envelope['signatures'][0]['value']);
        $this->assertSame('ed25519-jcs', $pack->envelope['signatures'][0]['algorithm']);
    }

    public function test_persisted_envelope_independently_verifies(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $pack = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertTrue((new DkpSigner())->verify($pack->envelope));
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

    public function test_confidence_is_at_floor_baseline_for_exactly_fifty_runs(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $pack = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertEqualsWithDelta(0.5, $pack->envelope['confidence'], 0.001);
    }
}
