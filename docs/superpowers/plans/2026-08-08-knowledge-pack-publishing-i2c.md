# Subsystem I2c: `incident_report` Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish one real, schema-valid, signed `incident_report` pack reporting the real `storage/framework` missing-directories bug (commit `73c4f3d`), reusing I2a/I2b's foundation.

**Architecture:** A new `IncidentPackGenerator` service, parameterized identically to `InsightPackGenerator`, builds/signs/persists an `incident_report` payload. A one-shot `dkp:generate-incident` Artisan command supplies this specific incident's real, hardcoded content. No new table/migration needed — `strategy_class`/`account_count` are already nullable from I2b.

**Tech Stack:** Laravel 12, `ext-sodium` (existing `DkpSigner`) — no new dependencies.

## Global Constraints

- `payload_type: "incident_report"` bodies must satisfy every required field in `schemas/incident.schema.json`: `incident_id`, `kind`, `severity`, `detection`, `impact`, `timeline`, `root_cause`, `corrective_actions`, `lessons` (per spec's Content section — exact values below).
- `period` column reused as idempotency slug (`"storage-framework-missing-2026-08-08"`), same convention as I2b (per spec's Architecture section).
- No scheduler entry, no general incident-authoring endpoint — one hardcoded, real incident (per spec's explicitly-out-of-scope list).

---

### Task 1: `IncidentPackGenerator`

**Files:**
- Create: `app/Services/IncidentPackGenerator.php`
- Test: `tests/Unit/IncidentPackGeneratorTest.php`

**Interfaces:**
- Consumes: `DkpSigner` (I2a), `KnowledgePack` (I2a/I2b).
- Produces: `IncidentPackGenerator::generate(string $slug, array $incidentBody, float $confidence): array` (returns `['generated' => bool, 'reason' => ?string, 'pack' => ?KnowledgePack]`) — consumed by Task 2's Artisan command. `$incidentBody` is the full `incident_report` body object (matching `schemas/incident.schema.json` exactly) — passed as one structured array rather than exploded into many parameters, since (unlike insight's 5 scalar-ish fields) the incident body's nested structure (`detection`, `impact`, `timeline[]`, `root_cause`, `corrective_actions[]`, `lessons[]`) doesn't usefully decompose into a flat parameter list.

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=IncidentPackGeneratorTest`
Expected: FAIL — `IncidentPackGenerator` does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Str;
use RuntimeException;

class IncidentPackGenerator
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    /**
     * Generates, signs, and persists one incident_report pack. Idempotent
     * per $slug. $incidentBody must match schemas/incident.schema.json's
     * required fields exactly.
     */
    public function generate(string $slug, array $incidentBody, float $confidence): array
    {
        $existing = KnowledgePack::where('payload_type', 'incident_report')
            ->where('period', $slug)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'pack' => $existing];
        }

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();

        $title = "Incident report: {$incidentBody['incident_id']}";
        $summary = $incidentBody['root_cause']['statement'];

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
                'payload_type' => 'incident_report',
                'body' => $incidentBody,
            ]],
            'provenance' => [
                'sources' => [[
                    'kind' => 'human_observation',
                    'uri' => 'chartsense://incident-report',
                    'observed_at' => $createdAt->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'author_and_sign',
                    'tool' => 'IncidentPackGenerator',
                    'tool_version' => '1.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => $confidence,
            'signatures' => [],
        ];

        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Generated Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'incident_report',
            'strategy_class' => null,
            'account_count' => null,
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => $slug,
            'envelope' => $envelope,
            'created_at' => $createdAt,
        ]);

        return ['generated' => true, 'reason' => null, 'pack' => $pack];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=IncidentPackGeneratorTest`
Expected: PASS (all 4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/IncidentPackGenerator.php tests/Unit/IncidentPackGeneratorTest.php
git commit -m "feat(knowledge-packs): add IncidentPackGenerator for the incident_report payload type"
```

---

### Task 2: `dkp:generate-incident` command + real generation + manual verification

**Files:**
- Create: `app/Console/Commands/GenerateIncidentPack.php`
- Test: `tests/Feature/GenerateIncidentCommandTest.php`

**Interfaces:**
- Consumes: `IncidentPackGenerator::generate()` (Task 1).
- Produces: `php artisan dkp:generate-incident` — one-shot, this specific incident's content hardcoded in the command.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateIncidentCommandTest extends TestCase
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

    public function test_command_generates_the_incident_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-incident')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $pack = KnowledgePack::first();
        $this->assertSame('incident_report', $pack->payload_type);
        $this->assertStringContainsString('storage/framework', $pack->envelope['payloads'][0]['body']['root_cause']['statement']);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-incident')->assertSuccessful();
        $this->artisan('dkp:generate-incident')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenerateIncidentCommandTest`
Expected: FAIL — command does not exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\IncidentPackGenerator;
use Illuminate\Console\Command;

class GenerateIncidentPack extends Command
{
    protected $signature = 'dkp:generate-incident';

    protected $description = 'Generate the real, one-off incident_report pack for the storage/framework missing-directories bug (commit 73c4f3d). Idempotent.';

    public function handle(IncidentPackGenerator $generator): int
    {
        $incidentBody = [
            'incident_id' => 'chartsense-inc-2026-08-08-001',
            'kind' => 'incident',
            'severity' => 'sev3',
            'detection' => [
                'detected_at' => '2026-08-08T17:46:20Z',
                'detected_by' => 'Manual verification during Strategy Builder F2 implementation pass (live php artisan serve testing)',
                'method' => 'A request expected to return 404 (nonexistent custom-strategy ID) returned 500 instead, surfaced only under the live dev server, not the test suite',
            ],
            'impact' => [
                'systems' => ['CustomStrategyController', 'BacktestController', 'Laravel framework cache/session/view compilation'],
                'description' => "Every firstOrFail()-based 404 response (in both controllers) was replaced by a 500 'Please provide a valid cache path' error under php artisan serve, because storage/framework/{cache,sessions,views} and storage/logs did not exist in the checkout at all, despite .gitignore expecting .gitkeep placeholders for them.",
            ],
            'timeline' => [
                ['at' => '2026-08-08T17:40:00Z', 'event' => 'F2 manual verification step requested a nonexistent custom-strategy ID, expecting a 404 JSON response'],
                ['at' => '2026-08-08T17:43:00Z', 'event' => "Observed a 500 'Please provide a valid cache path' error instead; root-caused to missing storage/framework and storage/logs directories"],
                ['at' => '2026-08-08T17:46:20Z', 'event' => 'Fix applied: restored the four missing directories with .gitkeep placeholders, verified the same request now returns 404 as expected'],
            ],
            'root_cause' => [
                'statement' => "storage/framework/{cache,sessions,views} and storage/logs did not exist in this checkout at all -- Laravel's live dev server needs these directories to write compiled views, cache entries, and sessions, and their absence caused every firstOrFail()-triggered exception handling path to fail with a filesystem error before it could render the intended 404 response.",
                'contributing_factors' => [
                    '.gitignore expected .gitkeep placeholders in these directories, but the placeholders themselves were never committed',
                    "php artisan test uses a different runtime config that doesn't need these paths, so the automated test suite never exercised this failure mode",
                ],
            ],
            'corrective_actions' => [
                [
                    'action' => 'Restore the missing storage/framework/{cache,sessions,views} and storage/logs directories with .gitkeep placeholders',
                    'owner' => 'ChartSense Platform Lead',
                    'due' => '2026-08-08',
                    'status' => 'done',
                ],
            ],
            'lessons' => [
                [
                    'lesson' => "Directories a framework needs at runtime but that git can't track empty (cache/session/log/view-compilation paths) must have committed .gitkeep placeholders verified present in a fresh checkout -- a passing test suite alone does not prove a live server will boot cleanly, if the test runtime config sidesteps the same paths.",
                    'verified' => true,
                    'verification_evidence' => 'Fix applied and independently re-verified live (php artisan serve, same request now returns the correct 404) in the same session; the missing-directories failure mode is a standard, well-understood Laravel deployment gotcha, not a novel or unverified claim.',
                ],
            ],
        ];

        $result = $generator->generate('storage-framework-missing-2026-08-08', $incidentBody, 0.95);

        if ($result['generated']) {
            $this->info("Generated incident pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: incident already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenerateIncidentCommandTest`
Expected: PASS (both tests)

- [ ] **Step 5: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/GenerateIncidentPack.php tests/Feature/GenerateIncidentCommandTest.php
git commit -m "feat(knowledge-packs): add dkp:generate-incident command with the real storage/framework incident"
```

- [ ] **Step 7: Manual end-to-end verification**

1. Start the backend dev server: `cd backend && php artisan serve`.
2. `php artisan dkp:generate-incident` — confirm a `dkp:dot-charts:<uuid>` pack ID prints.
3. Run it again — confirm "already generated" with the same pack ID.
4. Using an operator token, `GET /api/knowledge-packs` — confirm the incident pack appears with `payload_type: "incident_report"`.
5. `GET /api/knowledge-packs/{id}` for it — confirm the full `incident_report` body (all 8 required fields) and `signatures[0].algorithm === "ed25519-jcs"`.
6. Independently verify via tinker: `(new App\Services\DkpSigner())->verify(App\Models\KnowledgePack::where('payload_type','incident_report')->first()->envelope)` — confirm `true`.
7. Stop the dev server. Report results. No commit — verification only.
