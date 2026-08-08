<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use App\Services\KnowledgePackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class KnowledgePackApprovalServiceTest extends TestCase
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

    private function pendingPack(): KnowledgePack
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

        return KnowledgePack::create([
            'pack_id' => $envelope['pack_id'],
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'period' => 'test-slug',
            'envelope' => $envelope,
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);
    }

    public function test_approve_signs_the_envelope_and_flips_status(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $approved = (new KnowledgePackApprovalService)->approve($pack, $reviewer);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertNotSame([], $approved->envelope['signatures']);
        $this->assertTrue((new DkpSigner)->verify($approved->envelope));
    }

    public function test_approve_refuses_a_pack_that_is_not_pending(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();
        $pack->update(['status' => 'approved']);

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService)->approve($pack, $reviewer);
    }

    public function test_reject_requires_a_non_empty_reason(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService)->reject($pack, $reviewer, '   ');
    }

    public function test_reject_sets_status_and_reason_and_leaves_envelope_unsigned(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $rejected = (new KnowledgePackApprovalService)->reject($pack, $reviewer, 'Not accurate.');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Not accurate.', $rejected->rejected_reason);
        $this->assertSame($reviewer->id, $rejected->reviewed_by);
        $this->assertSame([], $rejected->envelope['signatures']);
    }

    public function test_reject_refuses_a_pack_that_is_not_pending(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();
        $pack->update(['status' => 'rejected']);

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService)->reject($pack, $reviewer, 'A reason.');
    }
}
