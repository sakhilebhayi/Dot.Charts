<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\IncidentPackGenerator;
use App\Services\InsightPackGenerator;
use App\Services\KnowledgePackApprovalService;
use App\Services\ObservationPackGenerator;
use App\Services\RecommendationPackGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

/**
 * Validates every real payload_type this platform generates against Dot.Brain's
 * own authoritative envelope schema (vendored at resources/dkp/, see the
 * README there for provenance) -- not a guess at what the schema might
 * require, the actual JSON Schema Dot.Brain's brain.dkp.md references.
 *
 * There is no live Dot.Brain endpoint to publish to yet (see wiki.md §5), so
 * this is the honest, verifiable substitute: proves every generated envelope
 * would pass Dot.Brain's own validation *if* a transport existed, and keeps
 * proving it automatically if either side's shape drifts.
 */
class DkpEnvelopeSchemaConformanceTest extends TestCase
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

    private function assertEnvelopeConformsToDkpSchema(array $envelope, string $context): void
    {
        $schemaPath = base_path('resources/dkp/knowledge-pack.schema.json');
        $this->assertFileExists($schemaPath, 'Vendored DKP schema is missing -- see resources/dkp/README.md.');

        $schema = json_decode(file_get_contents($schemaPath));
        // json_decode(..., false) (the default) produces stdClass for JSON
        // objects, matching what opis/json-schema expects -- a plain
        // associative array here would be indistinguishable from a JSON
        // array and fail every object-shaped assertion in the schema.
        $data = json_decode(json_encode($envelope));

        $result = (new Validator)->validate($data, $schema);

        if (! $result->isValid()) {
            $formatter = new ErrorFormatter;
            $errors = $formatter->format($result->error());
            $this->fail("{$context}: envelope does not conform to Dot.Brain's real DKP schema:\n".json_encode($errors, JSON_PRETTY_PRINT));
        }

        $this->assertTrue($result->isValid());
    }

    /**
     * insight/incident_report/recommendation packs generate as
     * pending_approval with an empty signatures[] -- by design, the whole
     * point of the approval gate (see KnowledgePackApprovalServiceTest).
     * The DKP schema requires signatures.minItems=1, so the schema-
     * conformant state to check is post-approval, not the instant after
     * generate(). (observation/metric packs are the one type that still
     * self-signs at generation -- see the dedicated test below.)
     */
    private function approve(KnowledgePack $pack): KnowledgePack
    {
        return (new KnowledgePackApprovalService)->approve($pack, User::factory()->create());
    }

    public function test_observation_metric_pack_conforms_to_the_real_dkp_schema(): void
    {
        // The period must be the month the runs actually land in: created_at
        // is not mass-assignable, so the explicit timestamps below were
        // silently dropped and every run was stamped now() - the test only
        // passed while "now" happened to fall inside the hardcoded month
        // (it detonated on the 1st of the next month). Anchor everything to
        // the current month so the fixture is calendar-proof.
        $start = now()->startOfMonth();
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => [
                    'metrics' => [
                        'total_return_pct' => 5.0,
                        'win_rate_pct' => 55.0,
                        'max_drawdown_pct' => -3.0,
                        'trade_count' => 12,
                        'losing_trade_count' => 5,
                    ],
                ],
                'created_at' => $start->copy()->addDays(1),
                'updated_at' => $start->copy()->addDays(1),
            ]);
        }

        $result = (new ObservationPackGenerator)->generateForPeriod('ma_crossover', now()->format('Y-m'));

        $this->assertTrue($result['generated'], 'Setup should have produced a real pack -- fix the fixture, not the assertion, if this fails.');
        $this->assertEnvelopeConformsToDkpSchema($result['pack']->envelope, 'observation/metric pack');
    }

    public function test_insight_pack_conforms_to_the_real_dkp_schema(): void
    {
        $result = (new InsightPackGenerator)->generate(
            slug: 'schema-conformance-insight-v1',
            statement: 'Test statement for schema conformance.',
            domain: 'test-domain',
            method: 'Test method.',
            evidence: [['kind' => 'external', 'reference' => 'chartsense://test', 'note' => 'test note']],
            scope: 'Test scope.',
            confidence: 0.85,
        );

        $approved = $this->approve($result['pack']);
        $this->assertEnvelopeConformsToDkpSchema($approved->envelope, 'insight pack');
    }

    public function test_incident_report_pack_conforms_to_the_real_dkp_schema(): void
    {
        $result = (new IncidentPackGenerator)->generate(
            'schema-conformance-incident-v1',
            [
                'incident_id' => 'schema-conformance-inc-001',
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
            ],
            0.95,
        );

        $approved = $this->approve($result['pack']);
        $this->assertEnvelopeConformsToDkpSchema($approved->envelope, 'incident_report pack');
    }

    public function test_recommendation_pack_conforms_to_the_real_dkp_schema(): void
    {
        $pack = (new RecommendationPackGenerator)->generate()['pack'];

        $approved = $this->approve($pack);
        $this->assertEnvelopeConformsToDkpSchema($approved->envelope, 'recommendation pack');
    }

    /**
     * Documents the flip side directly: a pending_approval pack (unsigned)
     * genuinely does NOT conform to Dot.Brain's schema, and that's correct
     * -- not something to work around. If this ever starts passing, either
     * the schema's signatures.minItems requirement changed upstream or
     * something started forging a signatures array before approval, and
     * either is worth knowing about.
     */
    public function test_an_unapproved_pack_does_not_conform_and_that_is_correct(): void
    {
        $result = (new InsightPackGenerator)->generate(
            slug: 'schema-conformance-unapproved-v1',
            statement: 'Test statement.',
            domain: 'test-domain',
            method: 'Test method.',
            evidence: [['kind' => 'external', 'reference' => 'chartsense://test', 'note' => 'test note']],
            scope: 'Test scope.',
            confidence: 0.85,
        );

        $this->assertSame('pending_approval', $result['pack']->status);
        $this->assertSame([], $result['pack']->envelope['signatures']);

        $schema = json_decode(file_get_contents(base_path('resources/dkp/knowledge-pack.schema.json')));
        $data = json_decode(json_encode($result['pack']->envelope));
        $validationResult = (new Validator)->validate($data, $schema);

        $this->assertFalse($validationResult->isValid(), 'An unapproved pack should not conform -- unsigned content is not yet publishable.');
    }
}
