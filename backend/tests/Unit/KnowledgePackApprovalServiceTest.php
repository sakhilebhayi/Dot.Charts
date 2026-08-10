<?php

namespace Tests\Unit;

use App\Models\DkpGateDecision;
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

    /**
     * A pack whose own summary matches config/dkp_instrument_map.php's seed
     * keyword ('kolomela') -- the outbound gate must catch this at approval
     * time even though nothing upstream stopped it from being generated.
     */
    private function pendingPackWithMnpiContent(): KnowledgePack
    {
        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => 'dkp:dot-charts:'.Str::uuid(),
            'title' => 'Regional mining output forecast',
            'summary' => 'Kolomela production is expected to increase 12% this quarter.',
            'payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'test']]],
            'confidence' => 0.9,
            'signatures' => [],
        ];

        return KnowledgePack::create([
            'pack_id' => $envelope['pack_id'],
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => $envelope['title'],
            'summary' => $envelope['summary'],
            'period' => 'test-slug-mnpi',
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

    public function test_approve_is_blocked_by_the_outbound_compliance_gate(): void
    {
        $pack = $this->pendingPackWithMnpiContent();
        $reviewer = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outbound compliance gate rejected');

        try {
            (new KnowledgePackApprovalService)->approve($pack, $reviewer);
        } finally {
            // Even on the exception path, the pack must not have been
            // signed or flipped to approved -- a caught/ignored exception
            // elsewhere must not leave a half-approved pack behind.
            $this->assertSame('pending_approval', $pack->fresh()->status);
            $this->assertSame([], $pack->fresh()->envelope['signatures']);
        }
    }

    public function test_approve_blocked_by_outbound_gate_still_writes_an_audit_row(): void
    {
        $pack = $this->pendingPackWithMnpiContent();
        $reviewer = User::factory()->create();

        try {
            (new KnowledgePackApprovalService)->approve($pack, $reviewer);
        } catch (RuntimeException) {
            // expected -- assertions below are the actual point of this test
        }

        $decision = DkpGateDecision::where('direction', 'outbound')->first();
        $this->assertNotNull($decision);
        $this->assertSame('reject', $decision->decision);
        $this->assertContains('kolomela', $decision->matched_keywords);
    }

    public function test_approve_of_clean_content_still_writes_a_passing_outbound_audit_row(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        (new KnowledgePackApprovalService)->approve($pack, $reviewer);

        $decision = DkpGateDecision::where('direction', 'outbound')->first();
        $this->assertNotNull($decision);
        $this->assertSame('pass', $decision->decision);
    }
}
