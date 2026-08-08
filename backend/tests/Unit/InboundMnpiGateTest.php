<?php

namespace Tests\Unit;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;
use App\Services\InboundMnpiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InboundMnpiGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pack_referencing_a_mapped_keyword_is_rejected(): void
    {
        $gate = new InboundMnpiGate();

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
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'General market sentiment analysis',
            'summary' => 'Overall bullish sentiment observed across tracked instruments this week.',
            'payloads' => [],
        ]);

        $this->assertSame('pass', $result['decision']);
        $this->assertEmpty($result['matched_keywords']);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'KOLOMELA output update',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $this->assertSame('reject', $result['decision']);
    }

    public function test_a_match_inside_a_payload_body_is_caught(): void
    {
        $gate = new InboundMnpiGate();

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

    public function test_every_call_writes_an_audit_log_row_regardless_of_outcome(): void
    {
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);
        $gate->screen(['title' => 'Clean pack', 'summary' => 'n/a', 'payloads' => []]);

        $this->assertSame(2, DkpGateDecision::count());
        $this->assertSame('reject', DkpGateDecision::orderBy('id')->first()->decision);
        $this->assertSame('pass', DkpGateDecision::orderBy('id')->get()->last()->decision);
    }

    public function test_rejection_dispatches_the_compliance_gate_rejected_event(): void
    {
        Event::fake();
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);

        Event::assertDispatched(ComplianceGateRejected::class);
    }

    public function test_a_pass_does_not_dispatch_the_event(): void
    {
        Event::fake();
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Clean pack', 'summary' => 'n/a', 'payloads' => []]);

        Event::assertNotDispatched(ComplianceGateRejected::class);
    }
}
