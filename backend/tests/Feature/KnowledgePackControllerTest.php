<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class KnowledgePackControllerTest extends TestCase
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

    private function operatorToken(): string
    {
        $operator = User::factory()->create(['is_platform_operator' => true]);
        return $operator->createToken('api')->plainTextToken;
    }

    private function seedEligibleMonth(): void
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
    }

    public function test_operator_can_trigger_generation(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response->assertOk();
        $response->assertJsonPath('generated', true);
        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_trigger_returns_below_floor_response_without_creating_a_pack(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response->assertOk();
        $response->assertJsonPath('generated', false);
        $response->assertJsonPath('reason', 'below_floor');
        $this->assertSame(0, KnowledgePack::count());
    }

    public function test_non_operator_gets_403(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson('/api/knowledge-packs/generate', ['strategy_class' => 'ma_crossover']);

        $response->assertStatus(401);
    }

    public function test_operator_can_list_packs_without_full_envelope(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/knowledge-packs');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonMissingPath('data.0.envelope');
        $response->assertJsonPath('data.0.strategy_class', 'ma_crossover');
        $response->assertJsonPath('data.0.payload_type', 'metric');
    }

    public function test_operator_can_view_a_single_pack_with_the_full_verifiable_envelope(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();
        $generateResponse = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);
        $packId = $generateResponse->json('pack.id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/knowledge-packs/{$packId}");

        $response->assertOk();
        $response->assertJsonPath('data.platform', 'dot-charts');
        $response->assertJsonStructure(['data' => ['payloads', 'signatures', 'provenance', 'confidence']]);

        $envelope = $response->json('data');
        $this->assertTrue((new DkpSigner())->verify($envelope));
    }
}
