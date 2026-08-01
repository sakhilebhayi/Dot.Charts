<?php

namespace Tests\Unit;

use App\Services\SignalErrorMetricsService;
use PHPUnit\Framework\TestCase;

class SignalErrorMetricsServiceTest extends TestCase
{
    private SignalErrorMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignalErrorMetricsService();
    }

    public function test_perfect_predictions_score_full_accuracy(): void
    {
        $predictions = ['buy', 'sell', 'hold', 'buy'];
        $actuals = ['buy', 'sell', 'hold', 'buy'];

        $result = $this->service->computeMetrics($predictions, $actuals);

        $this->assertEqualsWithDelta(1.0, $result['accuracy'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result['precision']['buy'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result['recall']['buy'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result['f1_score']['buy'], 0.0001);
    }

    public function test_all_wrong_predictions_score_zero_accuracy(): void
    {
        $predictions = ['buy', 'buy'];
        $actuals = ['sell', 'sell'];

        $result = $this->service->computeMetrics($predictions, $actuals);

        $this->assertEqualsWithDelta(0.0, $result['accuracy'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $result['precision']['sell'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $result['recall']['sell'], 0.0001);
    }

    public function test_mixed_predictions_compute_partial_accuracy(): void
    {
        // buy/buy match, sell/buy mismatch, buy/sell mismatch, sell/sell match -> 2 of 4 correct.
        $predictions = ['buy', 'sell', 'buy', 'sell'];
        $actuals =     ['buy', 'buy',  'sell', 'sell'];

        $result = $this->service->computeMetrics($predictions, $actuals);

        $this->assertEqualsWithDelta(0.5, $result['accuracy'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $result['precision']['buy'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $result['recall']['buy'], 0.0001);
    }

    public function test_empty_input_does_not_error(): void
    {
        $result = $this->service->computeMetrics([], []);

        $this->assertEqualsWithDelta(0, $result['accuracy'], 0.0001);
        $this->assertArrayHasKey('confusion_matrix', $result);
    }
}
