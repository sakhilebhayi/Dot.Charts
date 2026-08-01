<?php

namespace Tests\Unit;

use App\Services\SignalFeedbackService;
use PHPUnit\Framework\TestCase;

class SignalFeedbackServiceTest extends TestCase
{
    private string $feedbackFile;
    private SignalFeedbackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Use an isolated temp file so tests never touch the real
        // storage/signal_feedback.json used by the running app.
        $this->feedbackFile = tempnam(sys_get_temp_dir(), 'signal_feedback_test_') . '.json';
        @unlink($this->feedbackFile);
        $this->service = new SignalFeedbackService($this->feedbackFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->feedbackFile);
        parent::tearDown();
    }

    public function test_get_feedback_is_empty_when_no_file_exists(): void
    {
        $this->assertSame([], $this->service->getFeedback());
    }

    public function test_submit_feedback_persists_entry(): void
    {
        $ok = $this->service->submitFeedback('BTC', 'Buy', 'accurate', 'Nice call');

        $this->assertTrue($ok);
        $feedback = $this->service->getFeedback();
        $this->assertCount(1, $feedback);
        $this->assertSame('BTC', $feedback[0]['symbol']);
        $this->assertSame('Buy', $feedback[0]['signal']);
        $this->assertSame('accurate', $feedback[0]['feedback']);
        $this->assertSame('Nice call', $feedback[0]['comment']);
        $this->assertArrayHasKey('timestamp', $feedback[0]);
    }

    public function test_aggregate_feedback_counts_per_symbol_only(): void
    {
        $this->service->submitFeedback('BTC', 'Buy', 'accurate');
        $this->service->submitFeedback('BTC', 'Sell', 'inaccurate');
        $this->service->submitFeedback('BTC', 'Buy', 'accurate');
        $this->service->submitFeedback('ETH', 'Buy', 'neutral');

        $stats = $this->service->aggregateFeedback('BTC');

        $this->assertSame(2, $stats['accurate']);
        $this->assertSame(1, $stats['inaccurate']);
        $this->assertSame(0, $stats['neutral']);
        $this->assertSame(3, $stats['total']);
    }
}
