# Subsystem I2b: `insight` Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish one real, schema-valid, signed `insight` pack reporting ChartSense's AI-analysis honesty disclosure (Subsystem G's `is_demo`/`disclaimer` fields), reusing I2a's envelope/signing/API foundation.

**Architecture:** A small migration makes `strategy_class`/`account_count` nullable (inapplicable to a code-audit finding). A new `InsightPackGenerator` service takes the insight's content as parameters and builds/signs/persists the same envelope shape `ObservationPackGenerator` uses. A new one-shot Artisan command (`dkp:generate-insight`, no scheduler entry — this is a single historical fact, not a periodic cycle) supplies this specific insight's real content.

**Tech Stack:** Laravel 12, `ext-sodium` (existing `DkpSigner`) — no new dependencies.

## Global Constraints

- `payload_type: "insight"` payloads must satisfy `schemas/insight.schema.json`'s required fields: `statement`, `domain`, `method`, `evidence` (per spec's Content section — exact wording below).
- No aggregation floor applies to insight packs (per spec's Architecture section — this is not a `metric`/`observation` pack).
- `period` column is reused as a stable idempotency slug for insight packs (`"chart-analysis-demo-disclosure-v1"`), not a `YYYY-MM` value (per spec's Idempotency section).
- No scheduler entry, no admin "author any insight" endpoint — this slice ships exactly one hardcoded, real insight (per spec's explicitly-out-of-scope list).

---

### Task 1: Nullable `strategy_class`/`account_count`

**Files:**
- Create: `database/migrations/<timestamp>_make_knowledge_pack_strategy_fields_nullable.php`

**Interfaces:**
- Produces: `knowledge_packs.strategy_class` and `knowledge_packs.account_count` become nullable — consumed by Task 2 (`InsightPackGenerator` persists `null` for both).

- [ ] **Step 1: Write the migration**

Following I2a's established pattern for SQLite (drop then re-add, avoiding `->change()` which needs `doctrine/dbal`, not installed in this project):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['strategy_class', 'account_count']);
        });

        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->string('strategy_class')->nullable()->after('payload_type');
            $table->unsignedInteger('account_count')->nullable()->after('strategy_class');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['strategy_class', 'account_count']);
        });

        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->string('strategy_class')->after('payload_type');
            $table->unsignedInteger('account_count')->after('strategy_class');
        });
    }
};
```

Run: `php artisan make:migration make_knowledge_pack_strategy_fields_nullable` first for a correctly timestamped filename, then replace its contents with the above.

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: applies cleanly. Any existing dev-only `knowledge_packs` rows are unaffected in content (drop+re-add of these two columns loses their values for existing rows only — acceptable, dev-only sqlite data, same precedent as I2a).

- [ ] **Step 3: Confirm existing tests still pass**

Run: `php artisan test --filter=ObservationPackGeneratorSigningTest`
Expected: PASS — `ObservationPackGenerator` always supplies both fields, so nullability doesn't change its behavior.

- [ ] **Step 4: Commit**

```bash
git add database/migrations
git commit -m "feat(knowledge-packs): make strategy_class/account_count nullable for non-metric payload types"
```

---

### Task 2: `InsightPackGenerator`

**Files:**
- Create: `app/Services/InsightPackGenerator.php`
- Test: `tests/Unit/InsightPackGeneratorTest.php`

**Interfaces:**
- Consumes: `DkpSigner` (I2a), `KnowledgePack` (I2a/I2b Task 1).
- Produces: `InsightPackGenerator::generate(string $slug, string $statement, string $domain, string $method, array $evidence, string $scope, float $confidence): array` (returns `['generated' => bool, 'reason' => ?string, 'pack' => ?KnowledgePack]`) — consumed by Task 3's Artisan command.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Services\DkpSigner;
use App\Services\InsightPackGenerator;
use Tests\Concerns\UsesDkpTestKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        return (new InsightPackGenerator())->generate(
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

    public function test_confidence_is_passed_through_as_given(): void
    {
        $pack = $this->generate()['pack'];

        $this->assertEqualsWithDelta(0.85, $pack->envelope['confidence'], 0.001);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=InsightPackGeneratorTest`
Expected: FAIL — `InsightPackGenerator` does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Str;
use RuntimeException;

class InsightPackGenerator
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    /**
     * Generates, signs, and persists one insight pack. Idempotent per
     * $slug -- re-calling with the same slug returns the existing pack
     * without duplicating.
     */
    public function generate(
        string $slug,
        string $statement,
        string $domain,
        string $method,
        array $evidence,
        string $scope,
        float $confidence,
    ): array {
        $existing = KnowledgePack::where('payload_type', 'insight')
            ->where('period', $slug)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'pack' => $existing];
        }

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();

        $title = "Insight: {$domain}";
        $summary = $statement;

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
                'payload_type' => 'insight',
                'body' => [
                    'statement' => $statement,
                    'domain' => $domain,
                    'method' => $method,
                    'evidence' => $evidence,
                    'scope' => $scope,
                ],
            ]],
            'provenance' => [
                'sources' => [[
                    'kind' => 'human_observation',
                    'uri' => 'chartsense://code-audit',
                    'observed_at' => $createdAt->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'author_and_sign',
                    'tool' => 'InsightPackGenerator',
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
            'payload_type' => 'insight',
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

Run: `php artisan test --filter=InsightPackGeneratorTest`
Expected: PASS (all 5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/InsightPackGenerator.php tests/Unit/InsightPackGeneratorTest.php
git commit -m "feat(knowledge-packs): add InsightPackGenerator for the insight payload type"
```

---

### Task 3: `dkp:generate-insight` command + real generation + manual verification

**Files:**
- Create: `app/Console/Commands/GenerateInsightPack.php`
- Test: `tests/Feature/GenerateInsightCommandTest.php`

**Interfaces:**
- Consumes: `InsightPackGenerator::generate()` (Task 2).
- Produces: `php artisan dkp:generate-insight` — no arguments, one-shot, this specific insight's content is hardcoded in the command (per spec's explicit scope: not a general authoring tool).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class GenerateInsightCommandTest extends TestCase
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

    public function test_command_generates_the_insight_pack_on_first_run(): void
    {
        $this->artisan('dkp:generate-insight')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
        $pack = KnowledgePack::first();
        $this->assertSame('insight', $pack->payload_type);
        $this->assertStringContainsString('is_demo', $pack->envelope['payloads'][0]['body']['statement']);
    }

    public function test_command_reports_already_generated_on_second_run(): void
    {
        $this->artisan('dkp:generate-insight')->assertSuccessful();
        $this->artisan('dkp:generate-insight')->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenerateInsightCommandTest`
Expected: FAIL — command does not exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\InsightPackGenerator;
use Illuminate\Console\Command;

class GenerateInsightPack extends Command
{
    protected $signature = 'dkp:generate-insight';

    protected $description = 'Generate the real, one-off insight pack reporting the chart-analysis AI-honesty disclosure. Idempotent.';

    public function handle(InsightPackGenerator $generator): int
    {
        $result = $generator->generate(
            slug: 'chart-analysis-demo-disclosure-v1',
            statement: "ChartSense's chart-analysis endpoint (POST /api/chart/analyze) always discloses "
                . 'whether returned pattern-recognition results are placeholder/demo data or computed from '
                . 'real market data, via an explicit is_demo boolean and disclaimer field on every response '
                . '-- it never presents unlabeled placeholder output as if it were a real analysis.',
            domain: 'trading-analysis-integrity',
            method: 'Manual code audit of ChartAnalysisController::analyzeChart() and its placeholder '
                . 'fallback path, verified against the live API response schema.',
            evidence: [[
                'kind' => 'external',
                'reference' => 'chartsense://backend/app/Http/Controllers/ChartAnalysisController.php',
                'note' => 'is_demo/disclaimer fields present on both the real-analysis and placeholder response branches',
            ]],
            scope: 'Site-wide -- applies to every chart-analysis response, not a sampled subset.',
            confidence: 0.85,
        );

        if ($result['generated']) {
            $this->info("Generated insight pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: insight already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenerateInsightCommandTest`
Expected: PASS (both tests)

- [ ] **Step 5: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/GenerateInsightPack.php tests/Feature/GenerateInsightCommandTest.php
git commit -m "feat(knowledge-packs): add dkp:generate-insight command with the real honesty-disclosure insight"
```

- [ ] **Step 7: Manual end-to-end verification**

1. Start the backend dev server: `cd backend && php artisan serve`.
2. Run the real command: `php artisan dkp:generate-insight`. Confirm it prints a `dkp:dot-charts:<uuid>` pack ID.
3. Run it again: confirm it now prints "already generated" with the same pack ID (idempotency).
4. Using an operator token (create one via tinker as in I2a's verification, or reuse an existing operator account), `GET /api/knowledge-packs` — confirm the insight pack appears in the list alongside any `metric` packs, with `payload_type: "insight"`.
5. `GET /api/knowledge-packs/{id}` for the insight pack's ID — confirm the full envelope includes the `insight` payload with `statement`/`domain`/`method`/`evidence`/`scope`, and `signatures[0].algorithm === "ed25519-jcs"`.
6. Independently verify via tinker: `(new App\Services\DkpSigner())->verify(App\Models\KnowledgePack::where('payload_type','insight')->first()->envelope)` — confirm `true`.
7. Stop the dev server. Report results to the user. No commit — verification only.
