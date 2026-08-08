<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Services\DkpSigner;
use App\Services\RecommendationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class RecommendationPackGeneratorTest extends TestCase
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

    private function createMetricPack(bool $includesLossHonesty): void
    {
        $payloads = [
            ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_mean_return_pct']],
            ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_win_rate_pct']],
        ];
        if ($includesLossHonesty) {
            $payloads[] = ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_max_drawdown_worst_pct']];
            $payloads[] = ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_losing_period_pct']];
        }

        KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'metric',
            'strategy_class' => 'ma_crossover',
            'account_count' => 50,
            'pack_version' => '1.0.0',
            'title' => 'Test metric pack',
            'summary' => 'Test',
            'period' => '2026-08',
            'envelope' => ['payloads' => $payloads],
            'created_at' => now(),
        ]);
    }

    public function test_generates_a_signed_recommendation_pack(): void
    {
        $this->createMetricPack(includesLossHonesty: true);

        $result = (new RecommendationPackGenerator)->generate();

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertSame('recommendation', $pack->payload_type);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
    }

    public function test_recommendation_body_has_every_required_schema_field(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $pack = (new RecommendationPackGenerator)->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertSame('recommendation', $pack->envelope['payloads'][0]['payload_type']);
        $this->assertArrayHasKey('proposal', $body);
        $this->assertSame('dot-charts', $body['target_platform']);
        $this->assertArrayHasKey('rationale', $body);
        $this->assertArrayHasKey('business', $body['impact']);
        $this->assertArrayHasKey('user', $body['impact']);
        $this->assertArrayHasKey('dopamine', $body['impact']);
        $this->assertArrayHasKey('procedure', $body['rollback']);
        $this->assertGreaterThanOrEqual(1, $body['review_window_days']);
    }

    public function test_coverage_percentage_reflects_real_compliant_packs(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $this->createMetricPack(includesLossHonesty: true);

        $pack = (new RecommendationPackGenerator)->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(100.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_coverage_percentage_reflects_real_non_compliant_packs(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $this->createMetricPack(includesLossHonesty: false);

        $pack = (new RecommendationPackGenerator)->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(50.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_zero_existing_metric_packs_still_reports_the_code_level_guarantee(): void
    {
        $pack = (new RecommendationPackGenerator)->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(100.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_evidence_references_a_real_metric_pack_id_when_one_exists(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $metricPackId = KnowledgePack::where('payload_type', 'metric')->first()->pack_id;

        $pack = (new RecommendationPackGenerator)->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertContains($metricPackId, $body['evidence']);
    }

    public function test_persisted_pack_is_pending_approval_and_unsigned(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $pack = (new RecommendationPackGenerator)->generate()['pack'];

        $this->assertSame('pending_approval', $pack->status);
        $this->assertSame([], $pack->envelope['signatures']);
        $this->assertFalse((new DkpSigner)->verify($pack->envelope));
    }

    public function test_regenerating_does_not_duplicate(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $generator = new RecommendationPackGenerator;

        $first = $generator->generate();
        $second = $generator->generate();

        $this->assertTrue($first['generated']);
        $this->assertFalse($second['generated']);
        $this->assertSame('already_generated', $second['reason']);
    }
}
