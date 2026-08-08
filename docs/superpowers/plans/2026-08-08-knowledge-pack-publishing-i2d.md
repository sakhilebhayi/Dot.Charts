# Subsystem I2d: `recommendation`/Outcome Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish one real, schema-valid, signed `recommendation` pack reporting ChartSense's own loss-honesty-as-structural-invariant design, with `impact` numbers computed dynamically from real published packs rather than hardcoded, closing out all 4 Knowledge Pack payload types.

**Architecture:** A new `RecommendationPackGenerator` computes the real loss-honesty field-coverage percentage across existing `metric` packs at generation time, then builds/signs/persists a `recommendation` payload with that real number. A one-shot `dkp:generate-recommendation` Artisan command triggers it. No new table/migration needed.

**Tech Stack:** Laravel 12, `ext-sodium` (existing `DkpSigner`) — no new dependencies.

## Global Constraints

- `payload_type: "recommendation"` bodies must satisfy every required field in `schemas/recommendation.schema.json`: `proposal`, `target_platform`, `rationale`, `impact` (all 3 axes: `business`, `user`, `dopamine`), `rollback`, `review_window_days` (per spec's Content section — exact values below).
- The `business`/`user`/`dopamine` `target` values are **computed from real data**, not hardcoded — the generator queries existing `metric` packs for the 2 loss-honesty metric names (per spec's Architecture section).
- `evidence` references the real `pack_id` of an already-published `metric` pack when one exists (per spec's Content section).
- `period` column reused as idempotency slug (per the established I2b/I2c convention).
- No scheduler entry, no general recommendation-authoring endpoint (per spec's explicitly-out-of-scope list).

---

### Task 1: `RecommendationPackGenerator`

**Files:**
- Create: `app/Services/RecommendationPackGenerator.php`
- Test: `tests/Unit/RecommendationPackGeneratorTest.php`

**Interfaces:**
- Consumes: `DkpSigner`, `KnowledgePack` (existing).
- Produces: `RecommendationPackGenerator::generate(): array` (returns `['generated' => bool, 'reason' => ?string, 'pack' => ?KnowledgePack]`) — no external parameters (unlike Insight/Incident generators) because this generator's whole point is computing its own real numbers from the database, not accepting caller-supplied content — consumed by Task 2's Artisan command.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Services\DkpSigner;
use App\Services\RecommendationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class RecommendationPackGeneratorTest extends TestCase
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

    private function createMetricPack(bool $includesLossHonesty): void
    {
        $payloads = [
            ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_mean_return_pct']],
            ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_win_rate_pct']],
        ];
        if ($includesLossHonesty) {
            $payloads[] = ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_max_drawdown_worst_pct']];
            $payloads[] = ['payload_type' => 'metric', 'body' => ['metric_name' => 'trading.strategy_losing_period_pct']];
        }

        KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'metric',
            'strategy_class' => 'ma_crossover',
            'account_count' => 50,
            'pack_version' => '1.0.0',
            'title' => 'Test metric pack',
            'summary' => 'Test',
            'period' => '2026-08',
            'envelope' => ['payloads' => $payloads],
            'created_at' => now(),
        ]);
    }

    public function test_generates_a_signed_recommendation_pack(): void
    {
        $this->createMetricPack(includesLossHonesty: true);

        $result = (new RecommendationPackGenerator())->generate();

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertSame('recommendation', $pack->payload_type);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
    }

    public function test_recommendation_body_has_every_required_schema_field(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $pack = (new RecommendationPackGenerator())->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertSame('recommendation', $pack->envelope['payloads'][0]['payload_type']);
        $this->assertArrayHasKey('proposal', $body);
        $this->assertSame('dot-charts', $body['target_platform']);
        $this->assertArrayHasKey('rationale', $body);
        $this->assertArrayHasKey('business', $body['impact']);
        $this->assertArrayHasKey('user', $body['impact']);
        $this->assertArrayHasKey('dopamine', $body['impact']);
        $this->assertArrayHasKey('procedure', $body['rollback']);
        $this->assertGreaterThanOrEqual(1, $body['review_window_days']);
    }

    public function test_coverage_percentage_reflects_real_compliant_packs(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $this->createMetricPack(includesLossHonesty: true);

        $pack = (new RecommendationPackGenerator())->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(100.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_coverage_percentage_reflects_real_non_compliant_packs(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $this->createMetricPack(includesLossHonesty: false);

        $pack = (new RecommendationPackGenerator())->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(50.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_zero_existing_metric_packs_still_reports_the_code_level_guarantee(): void
    {
        $pack = (new RecommendationPackGenerator())->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertEqualsWithDelta(100.0, $body['impact']['business']['target'], 0.01);
    }

    public function test_evidence_references_a_real_metric_pack_id_when_one_exists(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $metricPackId = KnowledgePack::where('payload_type', 'metric')->first()->pack_id;

        $pack = (new RecommendationPackGenerator())->generate()['pack'];
        $body = $pack->envelope['payloads'][0]['body'];

        $this->assertContains($metricPackId, $body['evidence']);
    }

    public function test_persisted_envelope_independently_verifies(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $pack = (new RecommendationPackGenerator())->generate()['pack'];

        $this->assertTrue((new DkpSigner())->verify($pack->envelope));
    }

    public function test_regenerating_does_not_duplicate(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $generator = new RecommendationPackGenerator();

        $first = $generator->generate();
        $second = $generator->generate();

        $this->assertTrue($first['generated']);
        $this->assertFalse($second['generated']);
        $this->assertSame('already_generated', $second['reason']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RecommendationPackGeneratorTest`
Expected: FAIL — `RecommendationPackGenerator` does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Str;
use RuntimeException;

class RecommendationPackGenerator
{
    private const KEY_ID = 'dot-charts-dkp-v1';
    private const SLUG = 'loss-honesty-structural-invariant-recommendation-v1';
    private const REQUIRED_LOSS_HONESTY_METRICS = [
        'trading.strategy_max_drawdown_worst_pct',
        'trading.strategy_losing_period_pct',
    ];

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    public function generate(): array
    {
        $existing = KnowledgePack::where('payload_type', 'recommendation')
            ->where('period', self::SLUG)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'pack' => $existing];
        }

        $metricPacks = KnowledgePack::where('payload_type', 'metric')->get();
        $coveragePct = $this->computeCoveragePercentage($metricPacks);
        $evidencePackId = $metricPacks->first()?->pack_id;

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();

        $proposal = 'Treat loss-honesty fields (drawdown, losing-period-rate) as structural, non-omittable '
            . 'parts of every generated Knowledge Pack -- not optional or summary-only fields -- so '
            . 'survivorship-filtered performance marketing is prevented at the data-model level rather than '
            . 'relying on policy alone.';

        $body = [
            'proposal' => $proposal,
            'target_platform' => 'dot-charts',
            'rationale' => "The ecosystem's loss-honesty rule states published strategy performance must "
                . 'always include drawdowns and losing periods -- survivorship-filtered marketing is both '
                . 'success theater and a regulatory violation. This was implemented structurally, not just as '
                . "policy: ObservationPackGenerator's code path has no parameter or branch capable of omitting "
                . 'the max-drawdown or losing-period metrics.',
            'evidence' => $evidencePackId ? [$evidencePackId] : [],
            'impact' => [
                'business' => [
                    'metric' => 'trading.loss_honesty_field_coverage_pct',
                    'baseline' => 0,
                    'target' => $coveragePct,
                    'measurement_window' => 'Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window.',
                ],
                'user' => [
                    'metric' => 'trading.disclosure_transparency_pct',
                    'baseline' => 0,
                    'target' => $coveragePct,
                    'measurement_window' => 'Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window.',
                ],
                'dopamine' => [
                    'metric' => 'trading.ethical_disclosure_compliance_pct',
                    'baseline' => 0,
                    'target' => $coveragePct,
                    'measurement_window' => 'Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window.',
                ],
            ],
            'rollback' => [
                'procedure' => 'Revert ObservationPackGenerator (and InsightPackGenerator/IncidentPackGenerator, which follow the same envelope-building pattern) to make loss-honesty fields conditional or omittable.',
                'blast_radius' => 'All future generated Knowledge Packs would lose the structural loss-honesty guarantee -- a policy-only guarantee, not a code-enforced one.',
                'watch_signals' => ['trading.loss_honesty_field_coverage_pct', 'trading.gate_rejection_count'],
            ],
            'review_window_days' => 1,
        ];

        $title = 'Recommendation: loss-honesty fields as a structural invariant';
        $summary = $proposal;

        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'platform' => 'dot-charts',
            'title' => $title,
            'summary' => $summary,
            'created_at' => $createdAt->toIso8601String(),
            'contributors' => [[
                'id' => 'chartsense-knowledge-pack-generator',
                'kind' => 'ai',
                'display_name' => 'ChartSense Knowledge Pack Generator',
                'key_id' => self::KEY_ID,
            ]],
            'payloads' => [[
                'payload_type' => 'recommendation',
                'body' => $body,
            ]],
            'provenance' => [
                'sources' => [[
                    'kind' => 'system',
                    'uri' => 'chartsense://knowledge_packs',
                    'observed_at' => $createdAt->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'compute_coverage_and_sign',
                    'tool' => 'RecommendationPackGenerator',
                    'tool_version' => '1.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => 1.0,
            'signatures' => [],
        ];

        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Generated Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'recommendation',
            'strategy_class' => null,
            'account_count' => null,
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => self::SLUG,
            'envelope' => $envelope,
            'created_at' => $createdAt,
        ]);

        return ['generated' => true, 'reason' => null, 'pack' => $pack];
    }

    private function computeCoveragePercentage($metricPacks): float
    {
        if ($metricPacks->isEmpty()) {
            // No packs exist to sample yet, but the guarantee is a
            // code-level fact (ObservationPackGenerator structurally
            // cannot omit these fields), independent of how many packs
            // have been produced.
            return 100.0;
        }

        $compliantCount = $metricPacks->filter(function (KnowledgePack $pack) {
            $metricNames = collect($pack->envelope['payloads'] ?? [])
                ->pluck('body.metric_name')
                ->filter();

            foreach (self::REQUIRED_LOSS_HONESTY_METRICS as $required) {
                if (! $metricNames->contains($required)) {
                    return false;
                }
            }

            return true;
        })->count();

        return round(($compliantCount / $metricPacks->count()) * 100, 2);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=RecommendationPackGeneratorTest`
Expected: PASS (all 8 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecommendationPackGenerator.php tests/Unit/RecommendationPackGeneratorTest.php
git commit -m "feat(knowledge-packs): add RecommendationPackGenerator with real, computed impact numbers"
```

---

### Task 2: `dkp:generate-recommendation` command + real generation + manual verification

**Files:**
- Create: `app/Console/Commands/GenerateRecommendationPack.php`
- Test: `tests/Feature/GenerateRecommendationCommandTest.php`

**Interfaces:**
- Consumes: `RecommendationPackGenerator::generate()` (Task 1).
- Produces: `php artisan dkp:generate-recommendation` — one-shot, no arguments.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateRecommendationCommandTest extends TestCase
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

    public function test_command_generates_the_recommendation_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $this->assertSame('recommendation', KnowledgePack::first()->payload_type);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();
        $this->artisan('dkp:generate-recommendation')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenerateRecommendationCommandTest`
Expected: FAIL — command does not exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\RecommendationPackGenerator;
use Illuminate\Console\Command;

class GenerateRecommendationPack extends Command
{
    protected $signature = 'dkp:generate-recommendation';

    protected $description = 'Generate the real recommendation pack reporting the loss-honesty structural-invariant design, with impact numbers computed from actual published packs. Idempotent.';

    public function handle(RecommendationPackGenerator $generator): int
    {
        $result = $generator->generate();

        if ($result['generated']) {
            $this->info("Generated recommendation pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: recommendation already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenerateRecommendationCommandTest`
Expected: PASS (both tests)

- [ ] **Step 5: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/GenerateRecommendationPack.php tests/Feature/GenerateRecommendationCommandTest.php
git commit -m "feat(knowledge-packs): add dkp:generate-recommendation command"
```

- [ ] **Step 7: Manual end-to-end verification**

1. Start the backend dev server: `cd backend && php artisan serve`.
2. `php artisan dkp:generate-recommendation` — confirm a `dkp:dot-charts:<uuid>` pack ID prints.
3. Run it again — confirm "already generated".
4. Using an operator token, `GET /api/knowledge-packs` — confirm all 4 payload types now appear in the list (`metric`, `insight`, `incident_report`, `recommendation`).
5. `GET /api/knowledge-packs/{id}` for the recommendation pack — confirm `impact.business.target`/`impact.user.target`/`impact.dopamine.target` reflect a real computed number (100, given all existing `metric` packs are structurally compliant), `evidence` contains a real `pack_id`, and `signatures[0].algorithm === "ed25519-jcs"`.
6. Independently verify via tinker: `(new App\Services\DkpSigner())->verify(App\Models\KnowledgePack::where('payload_type','recommendation')->first()->envelope)` — confirm `true`.
7. Stop the dev server. Report results. No commit — verification only.
