<?php

namespace Tests\Feature;

use App\Models\DkpGateDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackIngestCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    private function operatorToken(): string
    {
        $operator = User::factory()->create(['is_platform_operator' => true]);
        return $operator->createToken('api')->plainTextToken;
    }

    public function test_operator_gets_pass_for_a_clean_pack(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'General market sentiment analysis',
            'payloads' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('decision', 'pass');
    }

    public function test_operator_gets_reject_for_a_pack_matching_the_instrument_map(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Kolomela output forecast',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('decision', 'reject');
        $response->assertJsonPath('matched_keywords.0', 'kolomela');
    }

    public function test_every_call_writes_exactly_one_audit_row(): void
    {
        $token = $this->operatorToken();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $this->assertSame(1, DkpGateDecision::count());
    }

    public function test_non_operator_gets_403(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertStatus(401);
    }
}
