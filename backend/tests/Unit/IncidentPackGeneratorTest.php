<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Services\DkpSigner;
use App\Services\IncidentPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class IncidentPackGeneratorTest extends TestCase
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

    private function testIncidentBody(): array
    {
        return [
            'incident_id' => 'test-inc-001',
            'kind' => 'incident',
            'severity' => 'sev3',
            'detection' => [
                'detected_at' => '2026-08-08T17:46:20Z',
                'detected_by' => 'Test detector',
                'method' => 'Test method',
            ],
            'impact' => [
                'systems' => ['TestSystem'],
                'description' => 'Test impact description',
            ],
            'timeline' => [
                ['at' => '2026-08-08T17:40:00Z', 'event' => 'Test event'],
            ],
            'root_cause' => [
                'statement' => 'Test root cause statement',
                'contributing_factors' => ['Test factor'],
            ],
            'corrective_actions' => [
                ['action' => 'Test action', 'owner' => 'Test owner', 'due' => '2026-08-08', 'status' => 'done'],
            ],
            'lessons' => [
                ['lesson' => 'Test lesson', 'verified' => true, 'verification_evidence' => 'Test evidence'],
            ],
        ];
    }

    private function generate(): array
    {
        return (new IncidentPackGenerator())->generate('test-incident-v1', $this->testIncidentBody(), 0.95);
    }

    public function test_generates_a_signed_incident_pack(): void
    {
        $result = $this->generate();

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertSame('incident_report', $pack->payload_type);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
    }

    public function test_incident_body_has_every_required_schema_field(): void
    {
        $pack = $this->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertSame('incident_report', $pack->envelope['payloads'][0]['payload_type']);
        $this->assertSame('test-inc-001', $body['incident_id']);
        $this->assertSame('incident', $body['kind']);
        $this->assertSame('sev3', $body['severity']);
        $this->assertArrayHasKey('detected_at', $body['detection']);
        $this->assertArrayHasKey('systems', $body['impact']);
        $this->assertCount(1, $body['timeline']);
        $this->assertArrayHasKey('statement', $body['root_cause']);
        $this->assertCount(1, $body['corrective_actions']);
        $this->assertCount(1, $body['lessons']);
        $this->assertTrue($body['lessons'][0]['verified']);
    }

    public function test_persisted_envelope_independently_verifies(): void
    {
        $pack = $this->generate()['pack'];

        $this->assertTrue((new DkpSigner())->verify($pack->envelope));
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
}
