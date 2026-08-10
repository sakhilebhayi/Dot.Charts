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
        $this->assertTrue((new DkpSigner)->verify($envelope));
    }

    public function test_pending_lists_only_pending_approval_packs(): void
    {
        $token = $this->operatorToken();
        $pending = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'pending-slug',
            'envelope' => ['payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'x']]], 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);
        \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Already approved',
            'summary' => 'Test',
            'period' => 'approved-slug',
            'envelope' => ['payloads' => [], 'signatures' => []],
            'status' => 'approved',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/knowledge-packs/pending');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_approve_signs_and_finalizes_a_pending_pack(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'approve-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/approve");

        $response->assertOk();
        $response->assertJson(['status' => 'approved']);
        $this->assertSame('approved', $pack->fresh()->status);
    }

    /**
     * Documents real, current behavior rather than assuming it: approve()
     * throws a plain RuntimeException when the outbound compliance gate
     * rejects (see KnowledgePackApprovalServiceTest for the service-level
     * coverage), and nothing in KnowledgePackController or the exception
     * handler catches that into a friendly 4xx today -- same pre-existing
     * gap as the "pack not pending" RuntimeException path had before this
     * change. Not fixed here (separate, broader concern: no RuntimeException
     * from this service maps to a clean HTTP response); this test exists so
     * that gap is asserted and visible rather than silently assumed away.
     */
    public function test_approve_blocked_by_outbound_gate_surfaces_as_a_server_error_today(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Kolomela production forecast',
            'summary' => 'Kolomela output expected to rise this quarter.',
            'period' => 'approve-mnpi-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/approve");

        $response->assertStatus(500);
        $this->assertSame('pending_approval', $pack->fresh()->status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'reject-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/reject", []);

        $response->assertStatus(422);
        $this->assertSame('pending_approval', $pack->fresh()->status);
    }

    public function test_reject_with_a_reason_marks_the_pack_rejected(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'reject-slug-2',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/reject", ['reason' => 'Not accurate.']);

        $response->assertOk();
        $response->assertJson(['status' => 'rejected', 'rejected_reason' => 'Not accurate.']);
        $this->assertSame('rejected', $pack->fresh()->status);
    }

    public function test_non_operator_cannot_approve(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.\Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'auth-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/approve");

        $response->assertStatus(403);
    }
}
