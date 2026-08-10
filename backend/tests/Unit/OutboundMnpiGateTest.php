<?php

namespace Tests\Unit;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;
use App\Services\OutboundMnpiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Mirrors InboundMnpiGateTest -- same matching logic (shared via the
 * ScreensMnpiContent trait), same fail-closed rule, only the recorded
 * 'direction' differs. See OutboundMnpiGate's docblock for why this exists
 * and where it's actually wired in (KnowledgePackApprovalService::approve).
 */
class OutboundMnpiGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pack_referencing_a_mapped_keyword_is_rejected(): void
    {
        $gate = new OutboundMnpiGate;

        $result = $gate->screen([
            'title' => 'Regional mining output forecast',
            'summary' => 'Kolomela production is expected to increase 12% this quarter.',
            'payloads' => [],
        ]);

        $this->assertSame('reject', $result['decision']);
        $this->assertContains('kolomela', $result['matched_keywords']);
    }

    public function test_a_pack_with_no_matches_passes(): void
    {
        $gate = new OutboundMnpiGate;

        $result = $gate->screen([
            'title' => 'General market sentiment analysis',
            'summary' => 'Overall bullish sentiment observed across tracked instruments this week.',
            'payloads' => [],
        ]);

        $this->assertSame('pass', $result['decision']);
        $this->assertEmpty($result['matched_keywords']);
    }

    public function test_a_match_inside_a_payload_body_is_caught(): void
    {
        $gate = new OutboundMnpiGate;

        $result = $gate->screen([
            'title' => 'Generic title',
            'summary' => 'Generic summary',
            'payloads' => [
                ['payload_type' => 'insight', 'body' => ['statement' => 'Sishen exports are trending upward.']],
            ],
        ]);

        $this->assertSame('reject', $result['decision']);
        $this->assertContains('sishen', $result['matched_keywords']);
    }

    public function test_audit_rows_are_recorded_with_outbound_direction(): void
    {
        $gate = new OutboundMnpiGate;

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);
        $gate->screen(['title' => 'Clean pack', 'summary' => 'n/a', 'payloads' => []]);

        $this->assertSame(2, DkpGateDecision::count());
        $this->assertTrue(DkpGateDecision::where('direction', 'outbound')->count() === 2);
        $this->assertSame('reject', DkpGateDecision::orderBy('id')->first()->decision);
        $this->assertSame('pass', DkpGateDecision::orderBy('id')->get()->last()->decision);
    }

    public function test_rejection_dispatches_the_compliance_gate_rejected_event(): void
    {
        Event::fake();
        $gate = new OutboundMnpiGate;

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);

        Event::assertDispatched(ComplianceGateRejected::class);
    }

    public function test_inbound_and_outbound_gates_write_distinguishable_audit_rows(): void
    {
        (new \App\Services\InboundMnpiGate)->screen(['title' => 'Clean inbound pack', 'summary' => 'n/a', 'payloads' => []]);
        (new OutboundMnpiGate)->screen(['title' => 'Clean outbound pack', 'summary' => 'n/a', 'payloads' => []]);

        $this->assertSame(1, DkpGateDecision::where('direction', 'inbound')->count());
        $this->assertSame(1, DkpGateDecision::where('direction', 'outbound')->count());
    }
}
