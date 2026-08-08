# Subsystem I2a: Real Envelope + Ed25519 Signing + Real Manifest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace I1's HMAC/flat-`observation` foundation with a real, schema-compliant Knowledge Pack: Ed25519 signing, RFC-8785-shaped canonical JSON, a full envelope (contributors, provenance, confidence, signatures), and loss-honesty preserved via 4 always-present `metric` payloads per pack instead of one payload with optional fields.

**Architecture:** A new `DkpSigner` service owns canonicalization/signing/verification against a real Ed25519 keypair (`ext-sodium`, generated once via a new Artisan command, gitignored). `ObservationPackGenerator` is reworked to assemble a full envelope and call `DkpSigner`, self-verifying before persisting. The `knowledge_packs` table, `KnowledgePack` model, and `KnowledgePackController` are reworked to store/serve the real envelope shape. `platform.dkp.json` is rewritten to validate against the real `schemas/platform-manifest.schema.json` and carries the real generated public key.

**Tech Stack:** Laravel 12 (existing backend), `ext-sodium` (confirmed available), PHPUnit/`php artisan test` — no new Composer dependencies.

## Global Constraints

- Every generated pack's `signatures[]` is Ed25519 (`algorithm: "ed25519-jcs"`), never HMAC — I1's `DKP_SIGNING_KEY`/`services.dkp.signing_key` config is removed entirely (per spec's Rework section).
- Every pack generation self-verifies its own output via `DkpSigner::verify()` before persisting; a verification failure throws `RuntimeException` rather than writing an unverifiable artifact (per spec's Canonicalization & Signing section — matches Dot.Billing's documented practice).
- Canonicalization is "RFC-8785-shaped" (recursive key-sort + compact JSON), not a certified strict-conformance JCS implementation — no such package exists in this stack (per spec's explicit scope note).
- Loss-honesty is preserved structurally as **exactly 4 metric payloads always present** (`trading.strategy_mean_return_pct`, `trading.strategy_win_rate_pct`, `trading.strategy_max_drawdown_worst_pct`, `trading.strategy_losing_period_pct`) — no code path may emit fewer (per spec's Metric-Body Mapping section).
- The real signing key file (`storage/app/private/dkp-ed25519.key`) is never committed — `.gitignore` must cover it before it's ever generated for real (per spec's Key Generation section).
- `platform.dkp.json` must satisfy every required field in `schemas/platform-manifest.schema.json` with no extra fields (`additionalProperties: false`) (per spec's Real Manifest section).
- This plan does not implement `insight`, `incident_report`, or `recommendation` payload types, registration with a running Dot.Brain, or any real transport/delivery (per spec's explicitly-out-of-scope list) — those are I2b/I2c/I2d and beyond.

---

### Task 1: Key generation command + config + gitignore + shared test-key trait

**Files:**
- Create: `app/Console/Commands/GenerateDkpKey.php`
- Modify: `config/services.php` (replace `dkp.signing_key` with `dkp.key_path`)
- Modify: `.gitignore`
- Create: `tests/Concerns/UsesDkpTestKey.php`
- Test: `tests/Feature/GenerateDkpKeyCommandTest.php`

**Interfaces:**
- Produces: `php artisan dkp:generate-key`, `config('services.dkp.key_path')` (default `storage_path('app/private/dkp-ed25519.key')`), the `UsesDkpTestKey` trait (`setUpDkpTestKey()`/`tearDownDkpTestKey()`) — consumed by every later task's tests that need a real (test) signing key.

- [ ] **Step 1: Update `config/services.php`**

Remove the `dkp.signing_key` block from I1 and replace it with:

```php
    // Dot Ecosystem Knowledge Pack signing (Subsystem I2a) -- real
    // Ed25519 keypair via ext-sodium, generated once with
    // `php artisan dkp:generate-key`. The secret key file is gitignored;
    // only its derived public key is committed, inside platform.dkp.json.
    'dkp' => [
        'key_path' => env('DKP_KEY_PATH', storage_path('app/private/dkp-ed25519.key')),
    ],
```

- [ ] **Step 2: Remove the obsolete `.env`/`.env.example` entry**

In `.env.example`, remove the `DKP_SIGNING_KEY=change-me-in-production` line (and its section header if nothing else is under it) added by I1 — it's replaced by the key-file mechanism, no env var needed for the default path.

- [ ] **Step 3: Update `.gitignore`**

Add, near the existing `/storage/*.key` line:

```
/storage/app/private/*.key
```

- [ ] **Step 4: Write the key-generation command**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateDkpKey extends Command
{
    protected $signature = 'dkp:generate-key';

    protected $description = 'Generate the Ed25519 signing keypair used to sign Knowledge Packs. Refuses to overwrite an existing key.';

    public function handle(): int
    {
        $path = config('services.dkp.key_path');

        if (file_exists($path)) {
            $this->error("Key already exists at {$path} -- refusing to overwrite. Regenerating would invalidate every previously-signed pack's verifiability against the manifest's committed public key.");

            return self::FAILURE;
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, base64_encode($secretKey));
        chmod($path, 0600);

        $this->info('Key generated at ' . $path);
        $this->info('Public key (paste into platform.dkp.json keys[0].public_key):');
        $this->line(base64_encode($publicKey));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Write the shared test-key trait**

```php
<?php

namespace Tests\Concerns;

trait UsesDkpTestKey
{
    private string $dkpTestKeyPath;

    protected function setUpDkpTestKey(): void
    {
        $this->dkpTestKeyPath = storage_path('app/private/test-dkp-' . uniqid() . '.key');
        $directory = dirname($this->dkpTestKeyPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $keypair = sodium_crypto_sign_keypair();
        file_put_contents($this->dkpTestKeyPath, base64_encode(sodium_crypto_sign_secretkey($keypair)));

        config(['services.dkp.key_path' => $this->dkpTestKeyPath]);
    }

    protected function tearDownDkpTestKey(): void
    {
        if (isset($this->dkpTestKeyPath) && file_exists($this->dkpTestKeyPath)) {
            unlink($this->dkpTestKeyPath);
        }
    }
}
```

- [ ] **Step 6: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class GenerateDkpKeyCommandTest extends TestCase
{
    private string $tempKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempKeyPath = storage_path('app/private/test-dkp-' . uniqid() . '.key');
        config(['services.dkp.key_path' => $this->tempKeyPath]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempKeyPath)) {
            unlink($this->tempKeyPath);
        }
        parent::tearDown();
    }

    public function test_it_creates_a_key_file_and_prints_a_valid_public_key(): void
    {
        $this->artisan('dkp:generate-key')->assertSuccessful();

        $this->assertFileExists($this->tempKeyPath);

        $secretKey = base64_decode(trim(file_get_contents($this->tempKeyPath)));
        $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        $signature = sodium_crypto_sign_detached('test-message', $secretKey);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'test-message', $derivedPublicKey));
    }

    public function test_it_refuses_to_overwrite_an_existing_key(): void
    {
        $this->artisan('dkp:generate-key')->assertSuccessful();
        $originalContent = file_get_contents($this->tempKeyPath);

        $this->artisan('dkp:generate-key')->assertFailed();

        $this->assertSame($originalContent, file_get_contents($this->tempKeyPath));
    }
}
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter=GenerateDkpKeyCommandTest`
Expected: PASS (both tests)

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/GenerateDkpKey.php config/services.php .env.example .gitignore tests/Concerns/UsesDkpTestKey.php tests/Feature/GenerateDkpKeyCommandTest.php
git commit -m "feat(knowledge-packs): add real Ed25519 key generation command"
```

---

### Task 2: `DkpSigner` service — canonicalize, sign, verify

**Files:**
- Create: `app/Services/DkpSigner.php`
- Test: `tests/Unit/DkpSignerTest.php`

**Interfaces:**
- Consumes: `config('services.dkp.key_path')` (Task 1).
- Produces: `DkpSigner::canonicalize(array): string`, `DkpSigner::sign(array $envelope): array` (returns a `signatures[]` array), `DkpSigner::verify(array $envelope): bool`, `DkpSigner::publicKey(): string` (raw bytes) — consumed by Task 4 (generator), Task 6 (manifest public key derivation).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Services\DkpSigner;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class DkpSignerTest extends TestCase
{
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

    public function test_canonicalize_is_deterministic_regardless_of_key_insertion_order(): void
    {
        $signer = new DkpSigner();

        $a = ['b' => 2, 'a' => 1, 'c' => ['y' => 2, 'x' => 1]];
        $b = ['a' => 1, 'c' => ['x' => 1, 'y' => 2], 'b' => 2];

        $this->assertSame($signer->canonicalize($a), $signer->canonicalize($b));
    }

    public function test_sign_then_verify_round_trip(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test', 'title' => 'Test Pack'];

        $envelope['signatures'] = $signer->sign($envelope);

        $this->assertTrue($signer->verify($envelope));
        $this->assertSame('dot-charts-dkp-v1', $envelope['signatures'][0]['key_id']);
        $this->assertSame('ed25519-jcs', $envelope['signatures'][0]['algorithm']);
    }

    public function test_tampering_with_the_envelope_after_signing_fails_verification(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test', 'title' => 'Test Pack'];
        $envelope['signatures'] = $signer->sign($envelope);

        $envelope['title'] = 'Tampered Title';

        $this->assertFalse($signer->verify($envelope));
    }

    public function test_verify_returns_false_when_no_signatures_present(): void
    {
        $signer = new DkpSigner();
        $envelope = ['pack_id' => 'dkp:dot-charts:test'];

        $this->assertFalse($signer->verify($envelope));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DkpSignerTest`
Expected: FAIL — `DkpSigner` class does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

class DkpSigner
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly ?string $keyPath = null)
    {
    }

    public function canonicalize(array $data): string
    {
        $this->recursiveKsort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function sign(array $envelope): array
    {
        $withoutSignatures = $envelope;
        unset($withoutSignatures['signatures']);

        $canonical = $this->canonicalize($withoutSignatures);
        $signatureBytes = sodium_crypto_sign_detached($canonical, $this->secretKey());

        return [[
            'key_id' => self::KEY_ID,
            'algorithm' => 'ed25519-jcs',
            'signed_at' => now()->toIso8601String(),
            'value' => base64_encode($signatureBytes),
        ]];
    }

    public function verify(array $envelope): bool
    {
        if (empty($envelope['signatures'][0]['value'])) {
            return false;
        }

        $withoutSignatures = $envelope;
        unset($withoutSignatures['signatures']);
        $canonical = $this->canonicalize($withoutSignatures);

        $signatureBytes = base64_decode($envelope['signatures'][0]['value']);

        return sodium_crypto_sign_verify_detached($signatureBytes, $canonical, $this->publicKey());
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    private function secretKey(): string
    {
        $path = $this->keyPath ?? config('services.dkp.key_path');

        return base64_decode(trim(file_get_contents($path)));
    }

    private function recursiveKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DkpSignerTest`
Expected: PASS (all 4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/DkpSigner.php tests/Unit/DkpSignerTest.php
git commit -m "feat(knowledge-packs): add DkpSigner with real Ed25519 canonicalize/sign/verify"
```

---

### Task 3: `knowledge_packs` table rework + `KnowledgePack` model

**Files:**
- Create: `database/migrations/<timestamp>_rework_knowledge_packs_for_real_envelope.php`
- Modify: `app/Models/KnowledgePack.php`
- Modify: `tests/Unit/KnowledgePackTest.php`

**Interfaces:**
- Produces: reworked `knowledge_packs` columns (`pack_version`, `title`, `summary`, `period`, `envelope`; drops `payload`, `signature`, `signing_key_version`, `period_start`, `period_end`) — consumed by Task 4 (generator persists to these columns), Task 7 (controller reads them).

`period` (string, e.g. `"2026-08"`) is a new flattened idempotency/query column — a deliberate addition beyond the design spec's exact wording ("period info moves inside the payload"), because `generateForPeriod()`'s already-generated check (I1's behavior, preserved here) needs a queryable period key now that `period_start`/`period_end` columns are gone. It is internal bookkeeping, not part of the real envelope.

- [ ] **Step 1: Write the migration**

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
            $table->dropColumn(['payload', 'signature', 'signing_key_version', 'period_start', 'period_end']);
            $table->string('pack_version')->default('1.0.0')->after('payload_type');
            $table->string('title')->after('pack_version');
            $table->text('summary')->after('title');
            $table->string('period')->after('summary'); // e.g. "2026-08" -- internal idempotency key, not part of the real envelope
            $table->json('envelope')->after('period');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['pack_version', 'title', 'summary', 'period', 'envelope']);
            $table->json('payload')->nullable();
            $table->string('signature')->nullable();
            $table->string('signing_key_version')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });
    }
};
```

Run: `php artisan make:migration rework_knowledge_packs_for_real_envelope` first for a correctly timestamped filename, then replace its contents with the above.

- [ ] **Step 2: Update the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgePack extends Model
{
    public $timestamps = false; // only created_at, set explicitly at create time

    protected $fillable = [
        'pack_id',
        'payload_type',
        'strategy_class',
        'account_count',
        'pack_version',
        'title',
        'summary',
        'period',
        'envelope',
        'created_at',
    ];

    protected $casts = [
        'envelope' => 'array',
        'created_at' => 'datetime',
    ];
}
```

- [ ] **Step 3: Rewrite the persistence test**

```php
<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_envelope_as_array(): void
    {
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:11111111-1111-4111-8111-111111111111',
            'payload_type' => 'metric',
            'strategy_class' => 'ma_crossover',
            'account_count' => 54,
            'pack_version' => '1.0.0',
            'title' => 'Test Pack',
            'summary' => 'A test pack',
            'period' => '2026-08',
            'envelope' => ['pack_id' => 'dkp:dot-charts:11111111-1111-4111-8111-111111111111', 'confidence' => 0.6],
            'created_at' => now(),
        ]);

        $fresh = KnowledgePack::find($pack->id);
        $this->assertIsArray($fresh->envelope);
        $this->assertEqualsWithDelta(0.6, $fresh->envelope['confidence'], 0.001);
        $this->assertSame('2026-08', $fresh->period);
    }
}
```

- [ ] **Step 4: Run migration and test**

Run: `php artisan migrate` then `php artisan test --filter=KnowledgePackTest`
Expected: migration applies cleanly (native SQLite `DROP COLUMN`, confirmed supported on this environment's SQLite 3.53), test passes.

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/KnowledgePack.php tests/Unit/KnowledgePackTest.php
git commit -m "feat(knowledge-packs): rework knowledge_packs table for the real envelope shape"
```

---

### Task 4: `ObservationPackGenerator` rework — 4-metric payloads + real envelope + signing

**Files:**
- Modify: `app/Services/ObservationPackGenerator.php`
- Modify: `tests/Unit/ObservationPackGeneratorTest.php`
- Modify: `tests/Unit/ObservationPackGeneratorSigningTest.php`

**Interfaces:**
- Consumes: `DkpSigner` (Task 2), reworked `KnowledgePack` (Task 3).
- Produces: `ObservationPackGenerator::buildMetricPayloads(string, Carbon, Carbon): array` (returns `['eligible' => bool, 'account_count' => int, 'run_count' => ?int, 'payloads' => ?array]`, where `payloads` is always exactly 4 metric-payload arrays when eligible), `ObservationPackGenerator::generateForPeriod(string, ?string): array` (same return contract as I1: `['generated' => bool, 'reason' => ?string, 'account_count' => ?int, 'pack' => ?KnowledgePack]`) — consumed by Task 7 (controller), Task 8 (Artisan command, unchanged).

- [ ] **Step 1: Write the failing tests for `buildMetricPayloads`**

Replace the entire contents of `tests/Unit/ObservationPackGeneratorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationPackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_METRIC_NAMES = [
        'trading.strategy_mean_return_pct',
        'trading.strategy_win_rate_pct',
        'trading.strategy_max_drawdown_worst_pct',
        'trading.strategy_losing_period_pct',
    ];

    private function completeRun(?User $user, string $strategy, float $totalReturnPct, float $maxDrawdownPct, Carbon $createdAt): BacktestRun
    {
        $run = BacktestRun::create([
            'user_id' => $user?->id,
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => $strategy,
            'params' => [],
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'status' => 'complete',
            'results' => [
                'metrics' => [
                    'total_return_pct' => $totalReturnPct,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => $maxDrawdownPct,
                    'trade_count' => 12,
                    'losing_trade_count' => 5,
                ],
            ],
        ]);
        $run->created_at = $createdAt;
        $run->save();

        return $run;
    }

    private function payloadFor(array $payloads, string $metricName): array
    {
        foreach ($payloads as $payload) {
            if ($payload['body']['metric_name'] === $metricName) {
                return $payload;
            }
        }

        $this->fail("No payload found for metric {$metricName}");
    }

    public function test_below_floor_returns_not_eligible(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 49; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertFalse($result['eligible']);
        $this->assertSame(49, $result['account_count']);
        $this->assertNull($result['payloads']);
    }

    public function test_at_floor_produces_exactly_four_metric_payloads(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['run_count']);
        $this->assertCount(4, $result['payloads']);
        $names = array_map(fn ($p) => $p['body']['metric_name'], $result['payloads']);
        sort($names);
        $expected = self::REQUIRED_METRIC_NAMES;
        sort($expected);
        $this->assertSame($expected, $names);
    }

    public function test_loss_honesty_metrics_present_and_correct_even_when_all_runs_win(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        $drawdown = $this->payloadFor($payloads, 'trading.strategy_max_drawdown_worst_pct');
        $losing = $this->payloadFor($payloads, 'trading.strategy_losing_period_pct');

        $this->assertEqualsWithDelta(-3.0, $drawdown['body']['observations'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(0.0, $losing['body']['observations'][0]['value'], 0.001);
        $this->assertSame('down', $drawdown['body']['direction_of_good']);
        $this->assertSame('down', $losing['body']['direction_of_good']);
    }

    public function test_loss_honesty_metrics_computed_correctly_with_a_realistic_loss_mix(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 30; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 20; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', -4.0, -12.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        $drawdown = $this->payloadFor($payloads, 'trading.strategy_max_drawdown_worst_pct');
        $losing = $this->payloadFor($payloads, 'trading.strategy_losing_period_pct');

        $this->assertEqualsWithDelta(-12.0, $drawdown['body']['observations'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(0.4, $losing['body']['observations'][0]['value'], 0.001);
    }

    public function test_anonymous_runs_are_excluded_from_both_account_count_and_metrics(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->completeRun(null, 'ma_crossover', 500.0, -90.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['run_count']);
        $return = $this->payloadFor($result['payloads'], 'trading.strategy_mean_return_pct');
        $this->assertEqualsWithDelta(5.0, $return['body']['observations'][0]['value'], 0.001);
    }

    public function test_each_metric_payload_carries_the_strategy_class_dimension(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        foreach ($payloads as $payload) {
            $this->assertSame('metric', $payload['payload_type']);
            $this->assertSame(['strategy_class'], $payload['body']['dimensions']);
            $this->assertSame('ma_crossover', $payload['body']['observations'][0]['dimensions']['strategy_class']);
            $this->assertSame(50, $payload['body']['observations'][0]['sample_size']);
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ObservationPackGeneratorTest`
Expected: FAIL — `buildMetricPayloads` does not exist yet (only the old `buildPayload` does).

- [ ] **Step 3: Rewrite `ObservationPackGenerator`**

Replace the entire contents of `app/Services/ObservationPackGenerator.php`:

```php
<?php

namespace App\Services;

use App\Events\StrategyPerformanceCycleCompleted;
use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class ObservationPackGenerator
{
    private const AGGREGATION_FLOOR = 50;
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    public static function knownStrategyClasses(): array
    {
        return [
            'ma_crossover',
            'rsi_mean_reversion',
            'method_714',
            'breakout',
            'bollinger_mean_reversion',
            'custom',
        ];
    }

    /**
     * Builds the 4 loss-honesty metric payloads for a strategy class over a
     * period, WITHOUT signing or persisting. Returns:
     *   ['eligible' => bool, 'account_count' => int, 'run_count' => ?int, 'payloads' => ?array]
     * `payloads` is always exactly 4 entries when eligible -- no code path
     * omits the drawdown/losing-period metrics.
     */
    public function buildMetricPayloads(string $strategyClass, Carbon $periodStart, Carbon $periodEnd): array
    {
        $runs = BacktestRun::where('strategy', $strategyClass)
            ->where('status', 'complete')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->get();

        $accountCount = $runs->pluck('user_id')->unique()->count();

        if ($accountCount < self::AGGREGATION_FLOOR) {
            return ['eligible' => false, 'account_count' => $accountCount, 'run_count' => null, 'payloads' => null];
        }

        $runCount = $runs->count();
        $returns = $runs->map(fn ($run) => (float) ($run->results['metrics']['total_return_pct'] ?? 0.0));
        $winRates = $runs->map(fn ($run) => (float) ($run->results['metrics']['win_rate_pct'] ?? 0.0));
        $drawdowns = $runs->map(fn ($run) => (float) ($run->results['metrics']['max_drawdown_pct'] ?? 0.0));
        $losingCount = $returns->filter(fn ($r) => $r < 0.0)->count();

        $observedAt = $periodEnd->copy()->endOfDay()->toIso8601String();

        $payloads = [
            $this->metricPayload(
                'trading.strategy_mean_return_pct',
                'Mean total_return_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor',
                'percent',
                'up',
                $strategyClass,
                round($returns->avg(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_win_rate_pct',
                'Mean win_rate_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor',
                'percent',
                'up',
                $strategyClass,
                round($winRates->avg(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_max_drawdown_worst_pct',
                'Worst single-run max_drawdown_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor -- always published, never omitted (loss-honesty rule)',
                'percent',
                'down',
                $strategyClass,
                round($drawdowns->min(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_losing_period_pct',
                'Fraction of complete backtest runs with a negative total_return_pct for this strategy class and period, among accounts meeting the n>=50 aggregation floor -- always published, never omitted (loss-honesty rule)',
                'ratio',
                'down',
                $strategyClass,
                round($losingCount / $runCount, 4),
                $runCount,
                $observedAt,
            ),
        ];

        return ['eligible' => true, 'account_count' => $accountCount, 'run_count' => $runCount, 'payloads' => $payloads];
    }

    /**
     * Full generation: builds the 4 metric payloads, checks the floor,
     * assembles the signed envelope, self-verifies, and persists on
     * success. Idempotent per (strategy_class, period).
     */
    public function generateForPeriod(string $strategyClass, ?string $period = null): array
    {
        $period = $period ?? now()->subMonthNoOverflow()->format('Y-m');
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $existing = KnowledgePack::where('strategy_class', $strategyClass)
            ->where('payload_type', 'metric')
            ->where('period', $period)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'account_count' => $existing->account_count, 'pack' => $existing];
        }

        $result = $this->buildMetricPayloads($strategyClass, $periodStart, $periodEnd);

        if (! $result['eligible']) {
            return ['generated' => false, 'reason' => 'below_floor', 'account_count' => $result['account_count'], 'pack' => null];
        }

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();
        $confidence = min(0.9, 0.5 + max(0, $result['run_count'] - 50) * 0.001);

        $title = "Strategy performance metrics: {$strategyClass}, {$period}";
        $summary = "Aggregate return, win-rate, and loss-honesty metrics for the {$strategyClass} strategy class across {$result['account_count']} accounts in {$period}.";

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
            'payloads' => $result['payloads'],
            'provenance' => [
                'sources' => [[
                    'kind' => 'system',
                    'uri' => 'chartsense://backtest_runs',
                    'observed_at' => $periodEnd->copy()->endOfDay()->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'aggregate_and_sign',
                    'tool' => 'ObservationPackGenerator',
                    'tool_version' => '2.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => round($confidence, 3),
            'signatures' => [],
        ];

        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Generated Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'metric',
            'strategy_class' => $strategyClass,
            'account_count' => $result['account_count'],
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => $period,
            'envelope' => $envelope,
            'created_at' => $createdAt,
        ]);

        StrategyPerformanceCycleCompleted::dispatch($pack->pack_id, $strategyClass, $result['account_count']);

        return ['generated' => true, 'reason' => null, 'account_count' => $result['account_count'], 'pack' => $pack];
    }

    private function metricPayload(
        string $metricName,
        string $definition,
        string $unit,
        string $direction,
        string $strategyClass,
        float $value,
        int $sampleSize,
        string $timestamp,
    ): array {
        return [
            'payload_type' => 'metric',
            'body' => [
                'metric_name' => $metricName,
                'domain' => 'trading',
                'definition' => $definition,
                'unit' => $unit,
                'direction_of_good' => $direction,
                'dimensions' => ['strategy_class'],
                'observations' => [[
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'dimensions' => ['strategy_class' => $strategyClass],
                    'sample_size' => $sampleSize,
                ]],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ObservationPackGeneratorTest`
Expected: PASS (all 6 tests)

- [ ] **Step 5: Rewrite the signing/persistence tests**

Replace the entire contents of `tests/Unit/ObservationPackGeneratorSigningTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class ObservationPackGeneratorSigningTest extends TestCase
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

    private function seedEligibleMonth(string $strategy, string $period): void
    {
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $run = BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => $strategy,
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => ['metrics' => [
                    'total_return_pct' => 5.0,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => -3.0,
                    'trade_count' => 12,
                    'losing_trade_count' => 5,
                ]],
            ]);
            $run->created_at = \Carbon\Carbon::parse($period . '-05');
            $run->save();
        }
    }

    public function test_generate_for_period_persists_a_signed_real_envelope(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertMatchesRegularExpression('/^dkp:dot-charts:[0-9a-f-]{36}$/', $pack->pack_id);
        $this->assertSame('metric', $pack->payload_type);
        $this->assertCount(4, $pack->envelope['payloads']);
        $this->assertNotEmpty($pack->envelope['signatures'][0]['value']);
        $this->assertSame('ed25519-jcs', $pack->envelope['signatures'][0]['algorithm']);
    }

    public function test_persisted_envelope_independently_verifies(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $pack = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertTrue((new DkpSigner())->verify($pack->envelope));
    }

    public function test_regenerating_the_same_strategy_and_period_does_not_duplicate(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $generator = new ObservationPackGenerator();

        $first = $generator->generateForPeriod('ma_crossover', '2026-08');
        $second = $generator->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($first['generated']);
        $this->assertFalse($second['generated']);
        $this->assertSame('already_generated', $second['reason']);
        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_below_floor_period_reports_reason_without_persisting(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create();
            $run = BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => ['metrics' => ['total_return_pct' => 5.0, 'win_rate_pct' => 55.0, 'max_drawdown_pct' => -3.0, 'trade_count' => 12, 'losing_trade_count' => 5]],
            ]);
            $run->created_at = \Carbon\Carbon::parse('2026-08-05');
            $run->save();
        }

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertFalse($result['generated']);
        $this->assertSame('below_floor', $result['reason']);
        $this->assertSame(10, $result['account_count']);
        $this->assertSame(0, KnowledgePack::count());
    }

    public function test_confidence_is_at_floor_baseline_for_exactly_fifty_runs(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $pack = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertEqualsWithDelta(0.5, $pack->envelope['confidence'], 0.001);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ObservationPackGeneratorSigningTest`
Expected: PASS (all 5 tests). Also re-run Step 4's test file to confirm no regression.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ObservationPackGenerator.php tests/Unit/ObservationPackGeneratorTest.php tests/Unit/ObservationPackGeneratorSigningTest.php
git commit -m "feat(knowledge-packs): rework ObservationPackGenerator for real envelope + Ed25519 signing"
```

---

### Task 5: Fix `StrategyPerformanceCycleEventTest`

**Files:**
- Modify: `tests/Unit/StrategyPerformanceCycleEventTest.php`

**Interfaces:**
- Consumes: `UsesDkpTestKey` (Task 1), reworked `ObservationPackGenerator` (Task 4). No interface changes to the event/listener themselves.

- [ ] **Step 1: Replace the obsolete HMAC config lines with the test-key trait**

In `tests/Unit/StrategyPerformanceCycleEventTest.php`, add `use Tests\Concerns\UsesDkpTestKey;` to the imports, add `use UsesDkpTestKey;` inside the class, and replace both occurrences of:

```php
        config(['services.dkp.signing_key' => 'test-signing-key']);
```

with:

```php
        $this->setUpDkpTestKey();
```

Add a `tearDown()` method (the class doesn't have one yet):

```php
    protected function tearDown(): void
    {
        $this->tearDownDkpTestKey();
        parent::tearDown();
    }
```

- [ ] **Step 2: Run tests**

Run: `php artisan test --filter=StrategyPerformanceCycleEventTest`
Expected: PASS (both tests, unchanged assertions — only the key setup mechanism changed)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/StrategyPerformanceCycleEventTest.php
git commit -m "test(knowledge-packs): switch StrategyPerformanceCycleEventTest to the real test-key trait"
```

---

### Task 6: Real key generation + real `platform.dkp.json` manifest

**Files:**
- Modify: `backend/platform.dkp.json`
- Modify: `tests/Unit/PlatformManifestTest.php`

**Interfaces:**
- Consumes: the real signing key at `storage/app/private/dkp-ed25519.key`, generated for real in Step 1 of this task (not a test fixture) — this is the one place in the plan where the real production key must exist for a test to pass, matching Dot.Billing/Dot.Emall's precedent of a real, gitignored key backing a real, committed public key.

- [ ] **Step 1: Generate the real key for this environment**

Run: `php artisan dkp:generate-key`

Copy the printed public key (base64) — it goes into the manifest in the next step. This creates `storage/app/private/dkp-ed25519.key`, which `.gitignore` (Task 1) already covers.

- [ ] **Step 2: Rewrite the manifest**

Replace the entire contents of `backend/platform.dkp.json` (substituting the real public key printed in Step 1, and the current UTC timestamp for `valid_from`):

```json
{
  "platform": "dot-charts",
  "display_name": "Dot.Charts",
  "dkp_version": "1.0.0",
  "endpoints": {
    "publish_topic": "dkp.dot-charts.publish",
    "response_topic": "dkp.dot-charts.response",
    "pr_repository": "git@github.com:sakhilebhayi/ChartSense.git"
  },
  "keys": [
    {
      "key_id": "dot-charts-dkp-v1",
      "algorithm": "ed25519",
      "public_key": "<paste the real base64 public key printed by dkp:generate-key>",
      "valid_from": "<current UTC ISO8601 timestamp>"
    }
  ],
  "advisory_subscriptions": ["all"],
  "rate_limit_per_minute": 100,
  "contacts": [{ "role": "Platform Owner", "handle": "@sakhilebhayi" }]
}
```

- [ ] **Step 3: Rewrite the manifest test**

Replace the entire contents of `tests/Unit/PlatformManifestTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\DkpSigner;
use Tests\TestCase;

class PlatformManifestTest extends TestCase
{
    public function test_manifest_has_every_field_the_real_schema_requires(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('dot-charts', $manifest['platform']);
        $this->assertSame('Dot.Charts', $manifest['display_name']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $manifest['dkp_version']);
        $this->assertArrayHasKey('publish_topic', $manifest['endpoints']);
        $this->assertArrayHasKey('response_topic', $manifest['endpoints']);
        $this->assertArrayHasKey('pr_repository', $manifest['endpoints']);
        $this->assertNotEmpty($manifest['contacts']);
    }

    public function test_manifest_key_algorithm_is_ed25519(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('ed25519', $manifest['keys'][0]['algorithm']);
        $this->assertSame('dot-charts-dkp-v1', $manifest['keys'][0]['key_id']);
    }

    public function test_manifest_public_key_matches_the_configured_signing_key(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);
        $manifestPublicKey = base64_decode($manifest['keys'][0]['public_key']);

        $derivedPublicKey = (new DkpSigner())->publicKey();

        $this->assertSame($derivedPublicKey, $manifestPublicKey);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=PlatformManifestTest`
Expected: PASS (all 3 tests) — this requires the real key generated in Step 1 to exist at `config('services.dkp.key_path')`'s default path; if it fails with a "file not found" style error, re-run Step 1.

- [ ] **Step 5: Commit**

```bash
git add backend/platform.dkp.json tests/Unit/PlatformManifestTest.php
git commit -m "feat(knowledge-packs): real Ed25519 key + platform.dkp.json validated against the real manifest schema"
```

Note: `storage/app/private/dkp-ed25519.key` itself is never committed (gitignored per Task 1) — only its derived public key, inside the manifest.

---

### Task 7: `KnowledgePackController` rework

**Files:**
- Modify: `app/Http/Controllers/KnowledgePackController.php`
- Modify: `tests/Feature/KnowledgePackControllerTest.php`

**Interfaces:**
- Consumes: reworked `KnowledgePack` (Task 3), reworked `ObservationPackGenerator::generateForPeriod()` (Task 4).
- Produces: same 3 routes as I1 (`POST /api/knowledge-packs/generate`, `GET /api/knowledge-packs`, `GET /api/knowledge-packs/{id}`) — response shapes change (`index` now omits `envelope`; `show` returns the full envelope directly under `data`).

- [ ] **Step 1: Write the failing tests**

Replace the entire contents of `tests/Feature/KnowledgePackControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class KnowledgePackControllerTest extends TestCase
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

    private function operatorToken(): string
    {
        $operator = User::factory()->create(['is_platform_operator' => true]);
        return $operator->createToken('api')->plainTextToken;
    }

    private function seedEligibleMonth(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $run = BacktestRun::create([
                'user_id' => $user->id,
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => [],
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
                'status' => 'complete',
                'results' => ['metrics' => ['total_return_pct' => 5.0, 'win_rate_pct' => 55.0, 'max_drawdown_pct' => -3.0, 'trade_count' => 12, 'losing_trade_count' => 5]],
            ]);
            $run->created_at = \Carbon\Carbon::parse('2026-08-05');
            $run->save();
        }
    }

    public function test_operator_can_trigger_generation(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response->assertOk();
        $response->assertJsonPath('generated', true);
        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_trigger_returns_below_floor_response_without_creating_a_pack(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response->assertOk();
        $response->assertJsonPath('generated', false);
        $response->assertJsonPath('reason', 'below_floor');
        $this->assertSame(0, KnowledgePack::count());
    }

    public function test_non_operator_gets_403(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson('/api/knowledge-packs/generate', ['strategy_class' => 'ma_crossover']);

        $response->assertStatus(401);
    }

    public function test_operator_can_list_packs_without_full_envelope(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/knowledge-packs');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonMissingPath('data.0.envelope');
        $response->assertJsonPath('data.0.strategy_class', 'ma_crossover');
        $response->assertJsonPath('data.0.payload_type', 'metric');
    }

    public function test_operator_can_view_a_single_pack_with_the_full_verifiable_envelope(): void
    {
        $this->seedEligibleMonth();
        $token = $this->operatorToken();
        $generateResponse = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/generate', [
            'strategy_class' => 'ma_crossover',
            'period' => '2026-08',
        ]);
        $packId = $generateResponse->json('pack.id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/knowledge-packs/{$packId}");

        $response->assertOk();
        $response->assertJsonPath('data.platform', 'dot-charts');
        $response->assertJsonStructure(['data' => ['payloads', 'signatures', 'provenance', 'confidence']]);

        $envelope = $response->json('data');
        $this->assertTrue((new DkpSigner())->verify($envelope));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=KnowledgePackControllerTest`
Expected: FAIL — controller still returns the old flat shape.

- [ ] **Step 3: Rewrite the controller**

Replace the entire contents of `app/Http/Controllers/KnowledgePackController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePack;
use App\Services\ObservationPackGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgePackController extends Controller
{
    public function __construct(
        private readonly ObservationPackGenerator $generator,
    ) {
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy_class' => 'required|string|in:' . implode(',', ObservationPackGenerator::knownStrategyClasses()),
            'period' => 'nullable|date_format:Y-m',
        ]);

        $result = $this->generator->generateForPeriod($validated['strategy_class'], $validated['period'] ?? null);

        return response()->json([
            'generated' => $result['generated'],
            'reason' => $result['reason'],
            'account_count' => $result['account_count'],
            'pack' => $result['pack'] ? ['id' => $result['pack']->id, 'pack_id' => $result['pack']->pack_id] : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $packs = KnowledgePack::orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (KnowledgePack $pack) => [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'title' => $pack->title,
                'payload_type' => $pack->payload_type,
                'strategy_class' => $pack->strategy_class,
                'account_count' => $pack->account_count,
                'confidence' => $pack->envelope['confidence'] ?? null,
                'created_at' => $pack->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $packs->items(), 'meta' => ['current_page' => $packs->currentPage(), 'last_page' => $packs->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $pack = KnowledgePack::findOrFail($id);

        return response()->json(['data' => $pack->envelope]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=KnowledgePackControllerTest`
Expected: PASS (all 6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/KnowledgePackController.php tests/Feature/KnowledgePackControllerTest.php
git commit -m "feat(knowledge-packs): serve the real signed envelope from the API"
```

---

### Task 8: Fix `GenerateKnowledgePacksCommandTest`

**Files:**
- Modify: `tests/Feature/GenerateKnowledgePacksCommandTest.php`

**Interfaces:**
- Consumes: `UsesDkpTestKey` (Task 1). The `GenerateKnowledgePacks` command itself (`app/Console/Commands/GenerateKnowledgePacks.php`) and `routes/console.php`'s scheduler entry need **no code changes** — they only call `generateForPeriod()`'s unchanged return contract.

- [ ] **Step 1: Replace the obsolete HMAC config with the test-key trait**

In `tests/Feature/GenerateKnowledgePacksCommandTest.php`, add `use Tests\Concerns\UsesDkpTestKey;` to the imports, add `use UsesDkpTestKey;` inside the class, replace:

```php
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dkp.signing_key' => 'test-signing-key']);
    }
```

with:

```php
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
```

- [ ] **Step 2: Run tests**

Run: `php artisan test --filter=GenerateKnowledgePacksCommandTest`
Expected: PASS (all 3 tests, unchanged assertions)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/GenerateKnowledgePacksCommandTest.php
git commit -m "test(knowledge-packs): switch GenerateKnowledgePacksCommandTest to the real test-key trait"
```

---

### Task 9: Full regression + manual end-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures — every I1-era test now exercises the real-envelope format; no test still references `services.dkp.signing_key`.

- [ ] **Step 2: Confirm no leftover references to the removed HMAC config**

Run: `grep -rn "signing_key\b" app/ config/ tests/ routes/` (excluding `signing_key_version` matches, which no longer exist either — confirm zero results, or only false-positive substring matches you've already reviewed).

- [ ] **Step 3: Start the backend dev server**

`cd backend && php artisan serve`

- [ ] **Step 4: Create an operator account and seed real activity via tinker**

```bash
php artisan tinker --execute="
\$u = App\Models\User::factory()->create(['email' => 'operator-i2a@example.com', 'is_platform_operator' => true]);
\$t = \$u->createToken('ops')->plainTextToken;
echo \$t;
"
```

```bash
php artisan tinker --execute="
for (\$i = 0; \$i < 50; \$i++) {
  \$u = App\Models\User::factory()->create();
  App\Models\BacktestRun::create([
    'user_id' => \$u->id, 'symbol' => 'AAPL', 'asset_class' => 'equity',
    'strategy' => 'ma_crossover', 'params' => [], 'start_date' => '2026-01-01',
    'end_date' => '2026-06-01', 'status' => 'complete',
    'results' => ['metrics' => ['total_return_pct' => 5.0 + \$i * 0.1, 'win_rate_pct' => 55.0, 'max_drawdown_pct' => -3.0 - \$i * 0.05, 'trade_count' => 12, 'losing_trade_count' => 5]],
  ]);
}
echo 'seeded';
"
```

- [ ] **Step 5: Trigger real generation and inspect the real signed envelope**

```bash
curl -s -X POST http://localhost:8000/api/knowledge-packs/generate \
  -H "Authorization: Bearer <token-from-step-4>" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"strategy_class": "ma_crossover", "period": "2026-08"}'
```

Confirm `pack_id` matches `dkp:dot-charts:<uuid>`.

```bash
curl -s http://localhost:8000/api/knowledge-packs/1 -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

Confirm the response has `payloads` (4 entries, `metric_name`s matching the required set), `contributors`, `provenance`, `confidence`, and `signatures[0].algorithm === "ed25519-jcs"`.

- [ ] **Step 6: Independently verify the signature outside the app**

```bash
php artisan tinker --execute="
\$pack = App\Models\KnowledgePack::first();
\$verified = (new App\Services\DkpSigner())->verify(\$pack->envelope);
echo \$verified ? 'VERIFIED' : 'FAILED';
"
```

Confirm output is `VERIFIED`.

- [ ] **Step 7: Confirm the manifest's public key matches**

```bash
cat platform.dkp.json | python3 -c "import json,sys; print(json.load(sys.stdin)['keys'][0]['public_key'])"
php artisan tinker --execute="echo base64_encode((new App\Services\DkpSigner())->publicKey());"
```

Confirm both values are identical.

- [ ] **Step 8: Stop the dev server. Report results to the user.**

No commit — this task is verification only.
