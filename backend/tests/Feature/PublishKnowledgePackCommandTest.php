<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\KnowledgePackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class PublishKnowledgePackCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesDkpTestKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDkpTestKey();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }

    private function approvedPack(): KnowledgePack
    {
        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => 'dkp:dot-charts:'.Str::uuid(),
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'test']]],
            'confidence' => 0.9,
            'signatures' => [],
        ];

        $pack = KnowledgePack::create([
            'pack_id' => $envelope['pack_id'],
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'period' => 'test-slug-'.Str::random(8),
            'envelope' => $envelope,
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        return (new KnowledgePackApprovalService)->approve($pack, User::factory()->create());
    }

    public function test_command_fails_clearly_when_no_endpoint_is_configured(): void
    {
        config(['services.brain.dkp_endpoint' => null]);
        $pack = $this->approvedPack();

        $this->artisan('dkp:publish', ['id' => $pack->id])
            ->expectsOutputToContain('No Dot.Brain DKP endpoint is configured')
            ->assertFailed();
    }

    public function test_command_fails_for_an_unknown_pack_id(): void
    {
        config(['services.brain.dkp_endpoint' => 'https://example.test/v1/dkp']);

        $this->artisan('dkp:publish', ['id' => 999999])
            ->assertFailed();
    }

    public function test_command_fails_for_a_pack_that_is_not_approved(): void
    {
        config(['services.brain.dkp_endpoint' => 'https://example.test/v1/dkp']);
        Http::fake();
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:'.Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Unapproved',
            'summary' => 'Test',
            'period' => 'unapproved-slug',
            'envelope' => ['payloads' => [], 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $this->artisan('dkp:publish', ['id' => $pack->id])
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_command_publishes_a_configured_approved_pack(): void
    {
        config(['services.brain.dkp_endpoint' => 'https://example.test/v1/dkp']);
        Http::fake(['*' => Http::response(['receipt_id' => 'receipt-xyz'], 202)]);
        $pack = $this->approvedPack();

        $this->artisan('dkp:publish', ['id' => $pack->id])
            ->expectsOutputToContain('receipt-xyz')
            ->assertSuccessful();
    }
}
