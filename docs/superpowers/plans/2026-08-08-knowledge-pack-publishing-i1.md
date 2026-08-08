# Subsystem I1: Knowledge Pack Publishing (Observation Packs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate schema-valid, HMAC-signed `observation` Knowledge Pack JSON artifacts from real ChartSense backtest data, aggregated across distinct users per strategy class, on a monthly schedule, retrievable via an operator-gated API.

**Architecture:** A new `ObservationPackGenerator` service queries `backtest_runs`, enforces the n≥50-distinct-account floor, and builds an immutable `KnowledgePack` row with a canonical-JSON HMAC-SHA256 signature. An Artisan command wraps the service and is the single code path used by both Laravel's built-in scheduler (monthly) and a new operator-only HTTP endpoint (on-demand). A `StrategyPerformanceCycleCompleted` event fires on success. A static `platform.dkp.json` manifest documents the publishing contract.

**Tech Stack:** Laravel 12 (existing backend), PHPUnit/`php artisan test` (existing test runner) — no new dependencies.

## Global Constraints

- Aggregation unit is **distinct users**, not runs — a pack for a strategy class/period is only generated once ≥50 distinct `user_id`s have a `complete` backtest run in that period (per design spec's Aggregation Unit decision).
- Only `backtest_runs` rows with a non-null `user_id` (i.e. tied to a real account) are ever included in aggregation — anonymous runs contribute to neither the account count nor the statistics (per spec's data model: aggregation is inherently account-scoped).
- Loss-honesty fields (`max_drawdown_p50_pct`, `max_drawdown_worst_pct`, `losing_period_count`, `losing_period_pct`) are structurally always present in every generated payload — no code path or parameter may omit them (per spec's Loss-Honesty section).
- `users.is_platform_operator` is never in `User::$fillable` and never settable via any request payload (per spec's Data Model section — hardening against mass assignment).
- Percentage fields use the existing codebase convention of `_pct`-suffixed values in percentage-point units (e.g. `8.3` not `0.083`), matching `BacktestMetrics`/`DisclosureFormatter` in `analytics/schemas.py` and `app/Services/DisclosureFormatter.php` — the design spec's illustrative fractional values (`0.083`) are notation only, not a literal field-naming requirement.
- `custom` strategies aggregate as one class (not per-saved-strategy-name), matching how `history.html`'s filter already treats them (per spec's Aggregation Query section).
- The trigger endpoint, list endpoint, and detail endpoint all require `auth:sanctum` **and** `is_platform_operator = true`; non-operators get `403`, unauthenticated callers get `401` (per spec's API section).
- No new payload types (`insight`/`outcome`/`incident`), no inbound MNPI gate, no delivery to Dot.Brain — this plan implements observation-pack generation only (per spec's explicitly-out-of-scope list).

---

### Task 1: `is_platform_operator` column + mass-assignment hardening

**Files:**
- Create: `database/migrations/<timestamp>_add_is_platform_operator_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/AuthControllerOperatorFlagTest.php`

**Interfaces:**
- Produces: `users.is_platform_operator` (boolean, default `false`), readable as `$user->is_platform_operator` — consumed by Task 8's `operator` middleware.

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
        Schema::table('users', function (Blueprint $table) {
            // Deliberately NOT named "is_admin" -- a generic admin flag name
            // is an obvious mass-assignment target to probe for. Scoped
            // naming plus exclusion from $fillable (see User model) means
            // no request payload can ever set this, regardless of name.
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

Run: `php artisan make:migration add_is_platform_operator_to_users_table` first to get a correctly timestamped filename, then replace its contents with the above.

- [ ] **Step 2: Update the User model — cast the column, do NOT add it to `$fillable`**

In `app/Models/User.php`, add a `boolean` cast for the new column inside the existing `casts()` method:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_operator' => 'boolean',
        ];
    }
```

Leave `$fillable` exactly as-is (`name`, `email`, `password`) — this is the mass-assignment guard. Add a one-line comment above `$fillable` explaining why:

```php
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    // is_platform_operator is deliberately excluded -- it must never be
    // settable via any request payload (register, profile update, or
    // otherwise). Only set via tinker, a seeder, or direct DB access.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
```

- [ ] **Step 3: Write the regression test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_payload_cannot_set_is_platform_operator(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Attempted Operator',
            'email' => 'attempt@example.com',
            'password' => 'password123',
            'is_platform_operator' => true,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'attempt@example.com')->firstOrFail();
        $this->assertFalse($user->is_platform_operator);
    }
}
```

- [ ] **Step 4: Run migration and test**

Run: `php artisan migrate` then `php artisan test --filter=AuthControllerOperatorFlagTest`
Expected: migration applies cleanly, test passes.

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/User.php tests/Feature/AuthControllerOperatorFlagTest.php
git commit -m "feat(knowledge-packs): add is_platform_operator flag, hardened against mass assignment"
```

---

### Task 2: `knowledge_packs` table + `KnowledgePack` model

**Files:**
- Create: `database/migrations/<timestamp>_create_knowledge_packs_table.php`
- Create: `app/Models/KnowledgePack.php`
- Test: `tests/Unit/KnowledgePackTest.php`

**Interfaces:**
- Produces: `KnowledgePack` model with fields `pack_id`, `payload_type`, `strategy_class`, `period_start`, `period_end`, `account_count`, `payload` (array cast), `signature`, `signing_key_version` — consumed by Task 3 (generator creates rows), Task 8 (controller reads rows).

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
        Schema::create('knowledge_packs', function (Blueprint $table) {
            $table->id();
            $table->string('pack_id')->unique();
            $table->string('payload_type'); // 'observation' for now
            $table->string('strategy_class');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('account_count');
            $table->json('payload');
            $table->string('signature');
            $table->string('signing_key_version');
            $table->timestamp('created_at')->useCurrent();
            // No updated_at -- packs are immutable once generated.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_packs');
    }
};
```

Run: `php artisan make:migration create_knowledge_packs_table` first for a correctly timestamped filename, then replace its contents with the above.

- [ ] **Step 2: Write the model**

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
        'period_start',
        'period_end',
        'account_count',
        'payload',
        'signature',
        'signing_key_version',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'datetime',
    ];
}
```

- [ ] **Step 3: Write a basic persistence test**

```php
<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_payload_as_array(): void
    {
        $pack = KnowledgePack::create([
            'pack_id' => 'dkp:charts:obs:2026-08-01:0001',
            'payload_type' => 'observation',
            'strategy_class' => 'ma_crossover',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'account_count' => 54,
            'payload' => ['strategy_class' => 'ma_crossover', 'account_count' => 54],
            'signature' => 'deadbeef',
            'signing_key_version' => 'v1',
            'created_at' => now(),
        ]);

        $fresh = KnowledgePack::find($pack->id);
        $this->assertIsArray($fresh->payload);
        $this->assertSame(54, $fresh->payload['account_count']);
        $this->assertSame('dkp:charts:obs:2026-08-01:0001', $fresh->pack_id);
    }
}
```

- [ ] **Step 4: Run migration and test**

Run: `php artisan migrate` then `php artisan test --filter=KnowledgePackTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/KnowledgePack.php tests/Unit/KnowledgePackTest.php
git commit -m "feat(knowledge-packs): add knowledge_packs table and KnowledgePack model"
```

---

### Task 3: `ObservationPackGenerator` — aggregation, floor check, loss-honesty payload

**Files:**
- Create: `app/Services/ObservationPackGenerator.php`
- Test: `tests/Unit/ObservationPackGeneratorTest.php`

**Interfaces:**
- Consumes: `App\Models\BacktestRun` (existing), `App\Models\KnowledgePack` (Task 2).
- Produces: `ObservationPackGenerator::knownStrategyClasses(): array`, `ObservationPackGenerator::buildPayload(string $strategyClass, Carbon $start, Carbon $end): array` (returns `['eligible' => bool, 'account_count' => int, 'payload' => ?array]`) — consumed by Task 4 (signing wraps this), Task 7 (Artisan command calls the full `generateForPeriod`).

This task builds the aggregation and payload-shaping logic in isolation, deliberately without signing or persistence yet — Task 4 adds those. This keeps the loss-honesty invariant (the part most worth testing in isolation) easy to test without needing a signing key configured.

- [ ] **Step 1: Write the failing tests**

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

    public function test_below_floor_returns_not_eligible(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 49 distinct users -- one short of the floor.
        for ($i = 0; $i < 49; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertFalse($result['eligible']);
        $this->assertSame(49, $result['account_count']);
        $this->assertNull($result['payload']);
    }

    public function test_at_floor_is_eligible_and_computes_aggregates(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 50 distinct users, all winners, no losing runs.
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['payload']['run_count']);
        $this->assertEqualsWithDelta(5.0, $result['payload']['mean_return_pct'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['payload']['median_return_pct'], 0.001);
    }

    public function test_loss_honesty_fields_always_present_even_when_all_runs_win(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payload = $generator->buildPayload('ma_crossover', $start, $end)['payload'];

        $this->assertArrayHasKey('max_drawdown_p50_pct', $payload);
        $this->assertArrayHasKey('max_drawdown_worst_pct', $payload);
        $this->assertArrayHasKey('losing_period_count', $payload);
        $this->assertArrayHasKey('losing_period_pct', $payload);
        $this->assertSame(0, $payload['losing_period_count']);
        $this->assertEqualsWithDelta(0.0, $payload['losing_period_pct'], 0.001);
        $this->assertEqualsWithDelta(-3.0, $payload['max_drawdown_p50_pct'], 0.001);
    }

    public function test_loss_honesty_fields_computed_correctly_with_a_realistic_loss_mix(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 30 winners, 20 losers -- 40% losing_period_pct.
        for ($i = 0; $i < 30; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 20; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', -4.0, -12.0, $start->copy()->addDays(1));
        }

        $payload = $generator->buildPayload('ma_crossover', $start, $end)['payload'];

        $this->assertSame(20, $payload['losing_period_count']);
        $this->assertEqualsWithDelta(0.4, $payload['losing_period_pct'], 0.001);
        $this->assertEqualsWithDelta(-12.0, $payload['max_drawdown_worst_pct'], 0.001);
    }

    public function test_anonymous_runs_are_excluded_from_both_account_count_and_statistics(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        // 10 anonymous runs with wildly different numbers -- must not
        // affect the floor check or the aggregates at all.
        for ($i = 0; $i < 10; $i++) {
            $this->completeRun(null, 'ma_crossover', 500.0, -90.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['payload']['run_count']);
        $this->assertEqualsWithDelta(5.0, $result['payload']['mean_return_pct'], 0.001);
    }

    public function test_custom_strategy_aggregates_across_all_saved_strategy_names_as_one_class(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // All rows share strategy = 'custom' regardless of which saved
        // strategy produced them (custom_strategies is a separate table --
        // backtest_runs.strategy is just the string 'custom').
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'custom', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('custom', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
    }

    public function test_only_complete_runs_within_the_period_count(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        // Failed run, and a run outside the period -- neither should count.
        $failedUser = User::factory()->create();
        $failed = $this->completeRun($failedUser, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        $failed->update(['status' => 'failed']);

        $outsideUser = User::factory()->create();
        $this->completeRun($outsideUser, 'ma_crossover', 5.0, -3.0, $start->copy()->subMonth());

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ObservationPackGeneratorTest`
Expected: FAIL — `ObservationPackGenerator` class does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\BacktestRun;
use Carbon\Carbon;

class ObservationPackGenerator
{
    private const AGGREGATION_FLOOR = 50;

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
     * Builds the observation payload for a strategy class over a period,
     * WITHOUT signing or persisting -- callers (Task 4's signing wrapper)
     * own that. Returns:
     *   ['eligible' => bool, 'account_count' => int, 'payload' => ?array]
     */
    public function buildPayload(string $strategyClass, Carbon $periodStart, Carbon $periodEnd): array
    {
        $runs = BacktestRun::where('strategy', $strategyClass)
            ->where('status', 'complete')
            ->whereNotNull('user_id') // anonymous runs never enter aggregation
            ->whereBetween('created_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->get();

        $accountCount = $runs->pluck('user_id')->unique()->count();

        if ($accountCount < self::AGGREGATION_FLOOR) {
            return ['eligible' => false, 'account_count' => $accountCount, 'payload' => null];
        }

        $returns = $runs->map(fn ($run) => (float) ($run->results['metrics']['total_return_pct'] ?? 0.0));
        $drawdowns = $runs->map(fn ($run) => (float) ($run->results['metrics']['max_drawdown_pct'] ?? 0.0));
        $losingRuns = $returns->filter(fn ($r) => $r < 0.0);

        $sortedReturns = $returns->sort()->values();
        $sortedDrawdowns = $drawdowns->sort()->values(); // ascending: most negative first

        $payload = [
            'payload_type' => 'observation',
            'strategy_class' => $strategyClass,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'account_count' => $accountCount,
            'run_count' => $runs->count(),
            'mean_return_pct' => round($returns->avg(), 3),
            'median_return_pct' => round($this->median($sortedReturns), 3),
            'win_rate_pct' => round($runs->avg(fn ($run) => (float) ($run->results['metrics']['win_rate_pct'] ?? 0.0)), 3),
            'max_drawdown_p50_pct' => round($this->median($sortedDrawdowns), 3),
            'max_drawdown_worst_pct' => round($sortedDrawdowns->first(), 3),
            'losing_period_count' => $losingRuns->count(),
            'losing_period_pct' => round($losingRuns->count() / $runs->count(), 4),
            'generated_at' => now()->toIso8601String(),
        ];

        return ['eligible' => true, 'account_count' => $accountCount, 'payload' => $payload];
    }

    private function median(\Illuminate\Support\Collection $sorted): float
    {
        $count = $sorted->count();
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }
        return $sorted[$mid];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ObservationPackGeneratorTest`
Expected: PASS (all 7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/ObservationPackGenerator.php tests/Unit/ObservationPackGeneratorTest.php
git commit -m "feat(knowledge-packs): add ObservationPackGenerator with aggregation floor and loss-honesty invariant"
```

---

### Task 4: Canonical-JSON signing + `KnowledgePack` persistence + idempotency

**Files:**
- Modify: `app/Services/ObservationPackGenerator.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Test: `tests/Unit/ObservationPackGeneratorSigningTest.php`

**Interfaces:**
- Consumes: `KnowledgePack` (Task 2), `ObservationPackGenerator::buildPayload()` (Task 3).
- Produces: `ObservationPackGenerator::generateForPeriod(string $strategyClass, ?string $period = null): array` (returns `['generated' => bool, 'reason' => ?string, 'account_count' => ?int, 'pack' => ?KnowledgePack]`) — consumed by Task 6 (event dispatch), Task 7 (Artisan command), Task 8 (controller).

- [ ] **Step 1: Add the signing key to config and `.env.example`**

In `config/services.php`, add after the `analytics` block:

```php
    // Dot Ecosystem Knowledge Pack signing (Subsystem I1) -- HMAC-SHA256,
    // not a real secrets vault (none exists in this codebase). Manifest
    // records this as signing_key_version "v1" for the vault:// naming
    // convention without requiring real vault infrastructure.
    'dkp' => [
        'signing_key' => env('DKP_SIGNING_KEY'),
    ],
```

In `.env.example`, add at the end:

```bash
# ============================================================================
# Dot Ecosystem Knowledge Pack signing (Subsystem I1)
# ============================================================================
DKP_SIGNING_KEY=change-me-in-production
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationPackGeneratorSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dkp.signing_key' => 'test-signing-key']);
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

    public function test_generate_for_period_persists_a_signed_pack(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');

        $result = (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        $this->assertTrue($result['generated']);
        $pack = $result['pack'];
        $this->assertInstanceOf(KnowledgePack::class, $pack);
        $this->assertSame('observation', $pack->payload_type);
        $this->assertSame('v1', $pack->signing_key_version);
        $this->assertMatchesRegularExpression('/^dkp:charts:obs:2026-08-01:\d{4}$/', $pack->pack_id);
        $this->assertNotEmpty($pack->signature);
    }

    public function test_signature_verifies_against_canonical_payload_and_fails_on_tamper(): void
    {
        $this->seedEligibleMonth('ma_crossover', '2026-08');
        $generator = new ObservationPackGenerator();
        $pack = $generator->generateForPeriod('ma_crossover', '2026-08')['pack'];

        $this->assertTrue($generator->verify($pack));

        $pack->payload = array_merge($pack->payload, ['mean_return_pct' => 999.0]);
        $this->assertFalse($generator->verify($pack));
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
        // Only 10 users -- below the floor.
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
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=ObservationPackGeneratorSigningTest`
Expected: FAIL — `generateForPeriod`/`verify` methods do not exist yet.

- [ ] **Step 4: Extend the implementation**

Add these methods and the `use` statements to `app/Services/ObservationPackGenerator.php` (alongside the existing `buildPayload`/`knownStrategyClasses`/`median` from Task 3):

```php
use App\Models\KnowledgePack;
```

```php
    /**
     * Full generation: builds the payload, checks the floor, signs and
     * persists on success. Idempotent per (strategy_class, period) --
     * re-running an already-generated period returns the existing pack's
     * reason without creating a duplicate row.
     */
    public function generateForPeriod(string $strategyClass, ?string $period = null): array
    {
        $period = $period ?? now()->subMonthNoOverflow()->format('Y-m');
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $existing = KnowledgePack::where('strategy_class', $strategyClass)
            ->where('payload_type', 'observation')
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'account_count' => $existing->account_count, 'pack' => $existing];
        }

        $result = $this->buildPayload($strategyClass, $periodStart, $periodEnd);

        if (! $result['eligible']) {
            return ['generated' => false, 'reason' => 'below_floor', 'account_count' => $result['account_count'], 'pack' => null];
        }

        $payload = $result['payload'];
        $payload['pack_id'] = $this->nextPackId($periodStart);

        $signature = $this->sign($payload);

        $pack = KnowledgePack::create([
            'pack_id' => $payload['pack_id'],
            'payload_type' => 'observation',
            'strategy_class' => $strategyClass,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'account_count' => $result['account_count'],
            'payload' => $payload,
            'signature' => $signature,
            'signing_key_version' => 'v1',
            'created_at' => now(),
        ]);

        return ['generated' => true, 'reason' => null, 'account_count' => $result['account_count'], 'pack' => $pack];
    }

    public function verify(KnowledgePack $pack): bool
    {
        return hash_equals($this->sign($pack->payload), $pack->signature);
    }

    private function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalize($payload), (string) config('services.dkp.signing_key'));
    }

    private function canonicalize(array $payload): string
    {
        $this->recursiveKsort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
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

    private function nextPackId(Carbon $periodStart): string
    {
        $count = KnowledgePack::where('payload_type', 'observation')
            ->whereDate('period_start', $periodStart->toDateString())
            ->count();

        return sprintf('dkp:charts:obs:%s:%04d', $periodStart->toDateString(), $count + 1);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ObservationPackGeneratorSigningTest`
Expected: PASS (all 4 tests). Also re-run Task 3's tests to confirm no regression: `php artisan test --filter=ObservationPackGeneratorTest`

- [ ] **Step 6: Commit**

```bash
git add app/Services/ObservationPackGenerator.php config/services.php .env.example tests/Unit/ObservationPackGeneratorSigningTest.php
git commit -m "feat(knowledge-packs): add canonical-JSON HMAC signing and idempotent persistence"
```

---

### Task 5: `platform.dkp.json` manifest

**Files:**
- Create: `backend/platform.dkp.json`
- Test: `tests/Unit/PlatformManifestTest.php`

**Interfaces:**
- Produces: a static, checked-in manifest file — no code consumes it yet (documentary, per spec; a later sub-project's delivery step would read it).

- [ ] **Step 1: Write the manifest**

```json
{
  "platform": "dot-charts",
  "version": "0.1.0",
  "publishes": ["observation"],
  "subscribes": [],
  "default_classification": "restricted",
  "signing_key": "vault://keys/dot-charts/dkp-signing/v1",
  "tenancy": {
    "aggregation_floor": 50
  }
}
```

- [ ] **Step 2: Write a test asserting it's valid and matches the current contract**

```php
<?php

namespace Tests\Unit;

use App\Services\ObservationPackGenerator;
use Tests\TestCase;

class PlatformManifestTest extends TestCase
{
    public function test_manifest_is_valid_json_and_declares_only_implemented_payload_types(): void
    {
        $manifest = json_decode(file_get_contents(base_path('platform.dkp.json')), true);

        $this->assertSame('dot-charts', $manifest['platform']);
        $this->assertSame(['observation'], $manifest['publishes']);
        $this->assertSame([], $manifest['subscribes']);
        $this->assertSame('restricted', $manifest['default_classification']);
        $this->assertSame(50, $manifest['tenancy']['aggregation_floor']);
    }
}
```

- [ ] **Step 3: Run test**

Run: `php artisan test --filter=PlatformManifestTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add platform.dkp.json tests/Unit/PlatformManifestTest.php
git commit -m "feat(knowledge-packs): add platform.dkp.json manifest declaring observation-only publishing"
```

---

### Task 6: `StrategyPerformanceCycleCompleted` event + listener

**Files:**
- Create: `app/Events/StrategyPerformanceCycleCompleted.php`
- Create: `app/Listeners/LogStrategyPerformanceCycle.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the listener)
- Modify: `app/Services/ObservationPackGenerator.php` (dispatch on success)
- Test: `tests/Unit/StrategyPerformanceCycleEventTest.php`

**Interfaces:**
- Consumes: `ObservationPackGenerator::generateForPeriod()` (Task 4) — dispatch point.
- Produces: `StrategyPerformanceCycleCompleted` event, dispatched with `pack_id`, `strategy_class`, `account_count` — no later task in this plan consumes it, but it's the integration point a future sub-project's real subscriber would hook into.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Events\StrategyPerformanceCycleCompleted;
use App\Models\BacktestRun;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StrategyPerformanceCycleEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_generating_a_pack_dispatches_the_performance_cycle_event(): void
    {
        config(['services.dkp.signing_key' => 'test-signing-key']);
        Event::fake();

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

        (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        Event::assertDispatched(StrategyPerformanceCycleCompleted::class, function ($event) {
            return $event->strategyClass === 'ma_crossover' && $event->accountCount === 50;
        });
    }

    public function test_below_floor_does_not_dispatch_the_event(): void
    {
        config(['services.dkp.signing_key' => 'test-signing-key']);
        Event::fake();

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

        (new ObservationPackGenerator())->generateForPeriod('ma_crossover', '2026-08');

        Event::assertNotDispatched(StrategyPerformanceCycleCompleted::class);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StrategyPerformanceCycleEventTest`
Expected: FAIL — event class does not exist yet.

- [ ] **Step 3: Write the event**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StrategyPerformanceCycleCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $packId,
        public readonly string $strategyClass,
        public readonly int $accountCount,
    ) {
    }
}
```

- [ ] **Step 4: Write the listener**

```php
<?php

namespace App\Listeners;

use App\Events\StrategyPerformanceCycleCompleted;
use Illuminate\Support\Facades\Log;

class LogStrategyPerformanceCycle
{
    public function handle(StrategyPerformanceCycleCompleted $event): void
    {
        // Satisfies the ecosystem spec's "events emitted" naming
        // (trading.strategy.performance_cycle) without a message bus --
        // none exists elsewhere in this codebase. A future subscriber
        // can be registered against this same event without changing it.
        Log::info('trading.strategy.performance_cycle', [
            'pack_id' => $event->packId,
            'strategy_class' => $event->strategyClass,
            'account_count' => $event->accountCount,
        ]);
    }
}
```

- [ ] **Step 5: Register the listener**

In `app/Providers/AppServiceProvider.php`, inside the `boot()` method, add:

```php
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\StrategyPerformanceCycleCompleted::class,
            \App\Listeners\LogStrategyPerformanceCycle::class,
        );
```

- [ ] **Step 6: Dispatch the event from the generator**

In `app/Services/ObservationPackGenerator.php`, add `use App\Events\StrategyPerformanceCycleCompleted;` to the imports, and in `generateForPeriod()`, right before the final `return ['generated' => true, ...]` line, add:

```php
        StrategyPerformanceCycleCompleted::dispatch($pack->pack_id, $strategyClass, $result['account_count']);

```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=StrategyPerformanceCycleEventTest`
Expected: PASS. Also re-run Task 4's tests: `php artisan test --filter=ObservationPackGeneratorSigningTest`

- [ ] **Step 8: Commit**

```bash
git add app/Events/StrategyPerformanceCycleCompleted.php app/Listeners/LogStrategyPerformanceCycle.php app/Providers/AppServiceProvider.php app/Services/ObservationPackGenerator.php tests/Unit/StrategyPerformanceCycleEventTest.php
git commit -m "feat(knowledge-packs): dispatch StrategyPerformanceCycleCompleted event on pack generation"
```

---

### Task 7: Artisan command + monthly scheduler

**Files:**
- Create: `app/Console/Commands/GenerateKnowledgePacks.php`
- Create: `routes/console.php`
- Test: `tests/Feature/GenerateKnowledgePacksCommandTest.php`

**Interfaces:**
- Consumes: `ObservationPackGenerator::generateForPeriod()` (Task 4), `ObservationPackGenerator::knownStrategyClasses()` (Task 3).
- Produces: `php artisan knowledge-packs:generate {strategy_class} {--period=}` — consumed by Task 8's controller (the manual-trigger endpoint calls the same command via `Artisan::call`).

`routes/console.php` does not exist yet in this codebase (it's referenced in `bootstrap/app.php`'s `commands:` path but was never created) — this task creates it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateKnowledgePacksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dkp.signing_key' => 'test-signing-key']);
    }

    public function test_command_generates_a_pack_for_an_eligible_strategy_and_period(): void
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

        $this->artisan('knowledge-packs:generate', ['strategy_class' => 'ma_crossover', '--period' => '2026-08'])
            ->assertSuccessful();

        $this->assertSame(1, KnowledgePack::count());
    }

    public function test_command_reports_below_floor_without_failing(): void
    {
        $this->artisan('knowledge-packs:generate', ['strategy_class' => 'ma_crossover', '--period' => '2026-08'])
            ->assertSuccessful();

        $this->assertSame(0, KnowledgePack::count());
    }

    public function test_scheduler_registers_the_monthly_call(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $this->assertTrue(
            $events->contains(fn ($event) => str_contains($event->command ?? '', 'knowledge-packs:generate') || $event->description === 'knowledge-packs-monthly-cycle')
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GenerateKnowledgePacksCommandTest`
Expected: FAIL — command does not exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\ObservationPackGenerator;
use Illuminate\Console\Command;

class GenerateKnowledgePacks extends Command
{
    protected $signature = 'knowledge-packs:generate {strategy_class} {--period=}';

    protected $description = 'Generate a signed observation Knowledge Pack for a strategy class and period, if the aggregation floor is met.';

    public function handle(ObservationPackGenerator $generator): int
    {
        $strategyClass = $this->argument('strategy_class');
        $period = $this->option('period');

        $result = $generator->generateForPeriod($strategyClass, $period);

        if ($result['generated']) {
            $this->info("Generated pack {$result['pack']->pack_id} for {$strategyClass} ({$result['account_count']} accounts).");
        } elseif ($result['reason'] === 'below_floor') {
            $this->info("Skipped {$strategyClass}: below aggregation floor ({$result['account_count']} accounts).");
        } else {
            $this->info("Skipped {$strategyClass}: pack already generated for this period ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Write `routes/console.php` with the monthly schedule**

```php
<?php

use App\Services\ObservationPackGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    foreach (ObservationPackGenerator::knownStrategyClasses() as $strategyClass) {
        Artisan::call('knowledge-packs:generate', ['strategy_class' => $strategyClass]);
    }
})->monthlyOn(1, '01:00')->description('knowledge-packs-monthly-cycle');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GenerateKnowledgePacksCommandTest`
Expected: PASS (all 3 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/GenerateKnowledgePacks.php routes/console.php tests/Feature/GenerateKnowledgePacksCommandTest.php
git commit -m "feat(knowledge-packs): add knowledge-packs:generate Artisan command and monthly scheduler"
```

---

### Task 8: Operator middleware + `KnowledgePackController` + routes

**Files:**
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Modify: `bootstrap/app.php` (register the `operator` middleware alias)
- Create: `app/Http/Controllers/KnowledgePackController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/KnowledgePackControllerTest.php`

**Interfaces:**
- Consumes: `is_platform_operator` (Task 1), `ObservationPackGenerator::generateForPeriod()` (Task 4), `KnowledgePack` (Task 2).
- Produces: `POST /api/knowledge-packs/generate`, `GET /api/knowledge-packs`, `GET /api/knowledge-packs/{id}` — final task, nothing downstream in this plan.

- [ ] **Step 1: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('sanctum')?->is_platform_operator) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Register the middleware alias**

In `bootstrap/app.php`, change:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
```

to:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => \App\Http\Middleware\EnsurePlatformOperator::class,
        ]);
    })
```

- [ ] **Step 3: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dkp.signing_key' => 'test-signing-key']);
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

    public function test_operator_can_list_packs_without_full_payload(): void
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
        $response->assertJsonMissingPath('data.0.payload');
        $response->assertJsonPath('data.0.strategy_class', 'ma_crossover');
    }

    public function test_operator_can_view_a_single_pack_with_full_payload(): void
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
        $response->assertJsonPath('data.strategy_class', 'ma_crossover');
        $response->assertJsonStructure(['data' => ['payload', 'signature']]);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --filter=KnowledgePackControllerTest`
Expected: FAIL — controller/routes do not exist yet.

- [ ] **Step 5: Write the controller**

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
                'strategy_class' => $pack->strategy_class,
                'period_start' => $pack->period_start->toDateString(),
                'period_end' => $pack->period_end->toDateString(),
                'account_count' => $pack->account_count,
                'created_at' => $pack->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $packs->items(), 'meta' => ['current_page' => $packs->currentPage(), 'last_page' => $packs->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $pack = KnowledgePack::findOrFail($id);

        return response()->json(['data' => [
            'id' => $pack->id,
            'pack_id' => $pack->pack_id,
            'strategy_class' => $pack->strategy_class,
            'period_start' => $pack->period_start->toDateString(),
            'period_end' => $pack->period_end->toDateString(),
            'account_count' => $pack->account_count,
            'payload' => $pack->payload,
            'signature' => $pack->signature,
            'signing_key_version' => $pack->signing_key_version,
            'created_at' => $pack->created_at->toIso8601String(),
        ]]);
    }
}
```

- [ ] **Step 6: Wire the routes**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\KnowledgePackController;
```

and inside the existing `Route::middleware('auth:sanctum')->group(function () { ... })` block, add a nested `operator`-gated group (after the existing `/strategies` routes):

```php
    Route::middleware('operator')->group(function () {
        Route::post('/knowledge-packs/generate', [KnowledgePackController::class, 'generate']);
        Route::get('/knowledge-packs', [KnowledgePackController::class, 'index']);
        Route::get('/knowledge-packs/{id}', [KnowledgePackController::class, 'show']);
    });
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=KnowledgePackControllerTest`
Expected: PASS (all 6 tests)

- [ ] **Step 8: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures (all prior subsystems' tests plus this plan's new tests).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Middleware/EnsurePlatformOperator.php bootstrap/app.php app/Http/Controllers/KnowledgePackController.php routes/api.php tests/Feature/KnowledgePackControllerTest.php
git commit -m "feat(knowledge-packs): add operator-gated generate/list/show API"
```

---

### Task 9: Manual end-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Start the backend dev server**

`cd backend && php artisan serve` (or the project's usual dev-server launch — analytics/frontend servers are not needed for this subsystem, it's backend-only).

- [ ] **Step 2: Create an operator account via tinker**

```bash
php artisan tinker --execute="
\$u = App\Models\User::factory()->create(['email' => 'operator@example.com', 'is_platform_operator' => true]);
\$t = \$u->createToken('ops')->plainTextToken;
echo \$t;
"
```

Save the printed token.

- [ ] **Step 3: Seed real backtest activity for one strategy class**

Via `curl` or the existing `backtest.html` frontend, run at least 50 real backtests as 50 distinct registered users for `ma_crossover` (or reuse existing seeded data if the dev DB already has ≥50 distinct users with complete `ma_crossover` runs this month — check with `php artisan tinker --execute="echo App\Models\BacktestRun::where('strategy','ma_crossover')->where('status','complete')->whereNotNull('user_id')->distinct('user_id')->count('user_id');"`).

If seeding manually is impractical, a tinker loop is acceptable for verification purposes only (not a substitute for the automated tests already run in Tasks 1-8):

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

- [ ] **Step 4: Trigger generation via curl**

```bash
curl -s -X POST http://localhost:8000/api/knowledge-packs/generate \
  -H "Authorization: Bearer <token-from-step-2>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"strategy_class": "ma_crossover"}'
```

Confirm the response shows `"generated": true` and a `pack_id` matching the `dkp:charts:obs:YYYY-MM-DD:NNNN` pattern.

- [ ] **Step 5: List and view via curl**

```bash
curl -s http://localhost:8000/api/knowledge-packs -H "Authorization: Bearer <token>" -H "Accept: application/json"
curl -s http://localhost:8000/api/knowledge-packs/1 -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

Confirm the list response omits `payload`, and the detail response includes `payload` (with `max_drawdown_p50_pct`, `max_drawdown_worst_pct`, `losing_period_count`, `losing_period_pct` all present) and a non-empty `signature`.

- [ ] **Step 6: Confirm non-operator/unauthenticated rejection**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/knowledge-packs
# Expected: 401 (no Authorization header)
```

Register a normal (non-operator) user, get their token, repeat the list call with that token — expect `403`.

- [ ] **Step 7: Confirm the scheduler is registered**

```bash
php artisan schedule:list
```

Confirm `knowledge-packs-monthly-cycle` (or the equivalent command line) appears with a monthly cadence.

- [ ] **Step 8: Stop the dev server. Report results to the user.**

No commit — this task is verification only, per the plan's final step.
