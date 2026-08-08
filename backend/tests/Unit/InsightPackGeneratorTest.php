<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Services\DkpSigner;
use App\Services\InsightPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class InsightPackGeneratorTest extends TestCase
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

    private function generate(): array
    {
        return (new InsightPackGenerator)->generate(
            slug: 'test-insight-v1',
            statement: 'Test statement.',
            domain: 'test-domain',
            method: 'Test method.',
            evidence: [['kind' => 'external', 'reference' => 'chartsense://test', 'note' => 'test note']],
            scope: 'Test scope.',
            confidence: 0.85,
        );
    }

    public function test_generates_a_signed_insight_pack(): void
    {
        $result = $this->generate();

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertSame('insight', $pack->payload_type);
        $this->assertNull($pack->strategy_class);
        $this->assertNull($pack->account_count);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
    }

    public function test_insight_payload_has_every_required_schema_field(): void
    {
        $pack = $this->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertSame('insight', $pack->envelope['payloads'][0]['payload_type']);
        $this->assertSame('Test statement.', $body['statement']);
        $this->assertSame('test-domain', $body['domain']);
        $this->assertSame('Test method.', $body['method']);
        $this->assertCount(1, $body['evidence']);
        $this->assertSame('external', $body['evidence'][0]['kind']);
        $this->assertSame('Test scope.', $body['scope']);
    }

    public function test_persisted_pack_is_pending_approval_and_unsigned(): void
    {
        $pack = $this->generate()['pack'];

        $this->assertSame('pending_approval', $pack->status);
        $this->assertSame([], $pack->envelope['signatures']);
        $this->assertFalse((new DkpSigner)->verify($pack->envelope));
    }

    public function test_regenerating_the_same_slug_does_not_duplicate(): void
    {
        $first = $this->generate();
        $second = $this->generate();

        $this->assertTrue($first['generated']);
        $this->assertFalse($second['generated']);
        $this->assertSame('already_generated', $second['reason']);
        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_confidence_is_passed_through_as_given(): void
    {
        $pack = $this->generate()['pack'];

        $this->assertEqualsWithDelta(0.85, $pack->envelope['confidence'], 0.001);
    }
}
