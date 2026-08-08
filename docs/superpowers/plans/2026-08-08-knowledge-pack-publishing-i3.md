# Subsystem I3: Inbound MNPI Content-Materiality Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the inbound half of the compliance gate — a content-materiality screen against a maintained instrument map, fail-closed, with every decision logged append-only and rejections dispatching the real `trading.compliance.gate_rejected` event.

**Architecture:** A small seed instrument-map config file, an `InboundMnpiGate` service doing coarse substring matching, a new `dkp_gate_decisions` append-only audit table, a `ComplianceGateRejected` event, and one operator-gated endpoint (`POST /api/knowledge-packs/ingest-check`) that ties them together.

**Tech Stack:** Laravel 12 — no new dependencies.

## Global Constraints

- The gate is fail-closed: any instrument-map keyword match rejects; there is no code path that attempts to judge "already public" from free text (per spec's `InboundMnpiGate` section).
- Classification/floor verification is NOT implemented — no real schema field exists for it; this is a deliberate, documented scope boundary, not an oversight (per spec's Context section).
- Every gate call (pass or reject) writes exactly one `dkp_gate_decisions` row — the audit log has no update/delete routes, ever (per spec's Audit Log section).
- Signature verification of inbound packs is out of scope (per spec's `InboundMnpiGate` section — no registry of other platforms' keys exists).

---

### Task 1: Instrument map + `dkp_gate_decisions` table + `ComplianceGateRejected` event

**Files:**
- Create: `config/dkp_instrument_map.php`
- Create: `database/migrations/<timestamp>_create_dkp_gate_decisions_table.php`
- Create: `app/Models/DkpGateDecision.php`
- Create: `app/Events/ComplianceGateRejected.php`
- Create: `app/Listeners/LogComplianceGateRejection.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces: `config('dkp_instrument_map')` (array), `DkpGateDecision` model, `ComplianceGateRejected` event — consumed by Task 2's `InboundMnpiGate`.

- [ ] **Step 1: Write the instrument map config**

```php
<?php

// Seed instrument map for the inbound MNPI content-materiality screen.
// Deliberately small and illustrative -- NOT a comprehensive or
// professionally-maintained instrument-mapping dataset. Mirrors the
// Kolomela/Kumba Iron Ore example already used in Dot.Brain's own
// brain.security.md §6 worked example (a false-correlation poisoning
// attempt targeting a Dot.Charts recommendation via a commodity/mining
// domain reference), rather than inventing a new fictional mapping.
// Per platforms/dot-charts.md §12's open question, this map's ongoing
// maintenance ownership is unresolved ecosystem-wide -- this seed exists
// so the gate has something real to check against, not as a claim that
// ChartSense unilaterally owns instrument-mapping maintenance.
return [
    'kolomela' => ['KIO.JO'],
    'sishen' => ['KIO.JO'],
    'kumba iron ore' => ['KIO.JO'],
];
```

Save as `config/dkp_instrument_map.php`.

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dkp_gate_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('direction'); // 'inbound' for this slice
            $table->string('decision'); // 'pass' or 'reject'
            $table->string('reason')->nullable();
            $table->json('matched_keywords')->nullable();
            $table->string('pack_title');
            $table->text('pack_summary');
            $table->timestamp('decided_at')->useCurrent();
            // No updated_at -- append-only, no update/delete routes ever exposed.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dkp_gate_decisions');
    }
};
```

Run: `php artisan make:migration create_dkp_gate_decisions_table` first for a correctly timestamped filename, then replace its contents with the above.

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DkpGateDecision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'direction',
        'decision',
        'reason',
        'matched_keywords',
        'pack_title',
        'pack_summary',
        'decided_at',
    ];

    protected $casts = [
        'matched_keywords' => 'array',
        'decided_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Write the event**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ComplianceGateRejected
{
    use Dispatchable;

    public function __construct(
        public readonly string $packTitle,
        public readonly array $matchedKeywords,
    ) {
    }
}
```

- [ ] **Step 5: Write the listener**

```php
<?php

namespace App\Listeners;

use App\Events\ComplianceGateRejected;
use Illuminate\Support\Facades\Log;

class LogComplianceGateRejection
{
    public function handle(ComplianceGateRejected $event): void
    {
        Log::info('trading.compliance.gate_rejected', [
            'pack_title' => $event->packTitle,
            'matched_keywords' => $event->matchedKeywords,
        ]);
    }
}
```

- [ ] **Step 6: Register the listener**

In `app/Providers/AppServiceProvider.php`'s `boot()` method, add (alongside the existing `StrategyPerformanceCycleCompleted` registration):

```php
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ComplianceGateRejected::class,
            \App\Listeners\LogComplianceGateRejection::class,
        );
```

- [ ] **Step 7: Run the migration**

Run: `php artisan migrate`
Expected: applies cleanly.

- [ ] **Step 8: Commit**

```bash
git add config/dkp_instrument_map.php database/migrations app/Models/DkpGateDecision.php app/Events/ComplianceGateRejected.php app/Listeners/LogComplianceGateRejection.php app/Providers/AppServiceProvider.php
git commit -m "feat(compliance-gate): add instrument map, gate-decisions audit table, and ComplianceGateRejected event"
```

---

### Task 2: `InboundMnpiGate` service

**Files:**
- Create: `app/Services/InboundMnpiGate.php`
- Test: `tests/Unit/InboundMnpiGateTest.php`

**Interfaces:**
- Consumes: `config('dkp_instrument_map')`, `DkpGateDecision`, `ComplianceGateRejected` (Task 1).
- Produces: `InboundMnpiGate::screen(array $pack): array` (returns `['decision' => 'pass'|'reject', 'reason' => ?string, 'matched_keywords' => array]`) — consumed by Task 3's controller. Writes the audit-log row and dispatches the event itself (callers don't need to duplicate that logic).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;
use App\Services\InboundMnpiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InboundMnpiGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pack_referencing_a_mapped_keyword_is_rejected(): void
    {
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'Regional mining output forecast',
            'summary' => 'Kolomela production is expected to increase 12% this quarter.',
            'payloads' => [],
        ]);

        $this->assertSame('reject', $result['decision']);
        $this->assertContains('kolomela', $result['matched_keywords']);
    }

    public function test_a_pack_with_no_matches_passes(): void
    {
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'General market sentiment analysis',
            'summary' => 'Overall bullish sentiment observed across tracked instruments this week.',
            'payloads' => [],
        ]);

        $this->assertSame('pass', $result['decision']);
        $this->assertEmpty($result['matched_keywords']);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'KOLOMELA output update',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $this->assertSame('reject', $result['decision']);
    }

    public function test_a_match_inside_a_payload_body_is_caught(): void
    {
        $gate = new InboundMnpiGate();

        $result = $gate->screen([
            'title' => 'Generic title',
            'summary' => 'Generic summary',
            'payloads' => [
                ['payload_type' => 'insight', 'body' => ['statement' => 'Sishen exports are trending upward.']],
            ],
        ]);

        $this->assertSame('reject', $result['decision']);
        $this->assertContains('sishen', $result['matched_keywords']);
    }

    public function test_every_call_writes_an_audit_log_row_regardless_of_outcome(): void
    {
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);
        $gate->screen(['title' => 'Clean pack', 'summary' => 'n/a', 'payloads' => []]);

        $this->assertSame(2, DkpGateDecision::count());
        $this->assertSame('reject', DkpGateDecision::orderBy('id')->first()->decision);
        $this->assertSame('pass', DkpGateDecision::orderBy('id')->get()->last()->decision);
    }

    public function test_rejection_dispatches_the_compliance_gate_rejected_event(): void
    {
        Event::fake();
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Kolomela update', 'summary' => 'n/a', 'payloads' => []]);

        Event::assertDispatched(ComplianceGateRejected::class);
    }

    public function test_a_pass_does_not_dispatch_the_event(): void
    {
        Event::fake();
        $gate = new InboundMnpiGate();

        $gate->screen(['title' => 'Clean pack', 'summary' => 'n/a', 'payloads' => []]);

        Event::assertNotDispatched(ComplianceGateRejected::class);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=InboundMnpiGateTest`
Expected: FAIL — `InboundMnpiGate` does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;

class InboundMnpiGate
{
    /**
     * Screens a raw pack envelope-shaped array against the instrument map.
     * Fail-closed: any keyword match rejects. No attempt to judge
     * "already public" from free text.
     */
    public function screen(array $pack): array
    {
        $haystack = $this->buildHaystack($pack);
        $matchedKeywords = [];

        foreach (config('dkp_instrument_map', []) as $keyword => $instruments) {
            if (str_contains($haystack, strtolower($keyword))) {
                $matchedKeywords[] = $keyword;
            }
        }

        $decision = empty($matchedKeywords) ? 'pass' : 'reject';
        $reason = $decision === 'reject' ? 'MNPI content-materiality match' : null;

        DkpGateDecision::create([
            'direction' => 'inbound',
            'decision' => $decision,
            'reason' => $reason,
            'matched_keywords' => $matchedKeywords ?: null,
            'pack_title' => $pack['title'] ?? '',
            'pack_summary' => $pack['summary'] ?? '',
            'decided_at' => now(),
        ]);

        if ($decision === 'reject') {
            ComplianceGateRejected::dispatch($pack['title'] ?? '', $matchedKeywords);
        }

        return ['decision' => $decision, 'reason' => $reason, 'matched_keywords' => $matchedKeywords];
    }

    private function buildHaystack(array $pack): string
    {
        $parts = [$pack['title'] ?? '', $pack['summary'] ?? ''];

        foreach ($pack['payloads'] ?? [] as $payload) {
            $parts[] = $this->stringify($payload['body'] ?? []);
        }

        return strtolower(implode(' ', $parts));
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map($this->stringify(...), $value));
        }

        return (string) $value;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=InboundMnpiGateTest`
Expected: PASS (all 7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/InboundMnpiGate.php tests/Unit/InboundMnpiGateTest.php
git commit -m "feat(compliance-gate): add InboundMnpiGate content-materiality screen"
```

---

### Task 3: `POST /api/knowledge-packs/ingest-check` endpoint

**Files:**
- Modify: `app/Http/Controllers/KnowledgePackController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/KnowledgePackIngestCheckControllerTest.php`

**Interfaces:**
- Consumes: `InboundMnpiGate::screen()` (Task 2).
- Produces: `POST /api/knowledge-packs/ingest-check` — operator-gated, same pattern as the other Knowledge Pack routes.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\DkpGateDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgePackIngestCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    private function operatorToken(): string
    {
        $operator = User::factory()->create(['is_platform_operator' => true]);
        return $operator->createToken('api')->plainTextToken;
    }

    public function test_operator_gets_pass_for_a_clean_pack(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'General market sentiment analysis',
            'payloads' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('decision', 'pass');
    }

    public function test_operator_gets_reject_for_a_pack_matching_the_instrument_map(): void
    {
        $token = $this->operatorToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Kolomela output forecast',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('decision', 'reject');
        $response->assertJsonPath('matched_keywords.0', 'kolomela');
    }

    public function test_every_call_writes_exactly_one_audit_row(): void
    {
        $token = $this->operatorToken();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $this->assertSame(1, DkpGateDecision::count());
    }

    public function test_non_operator_gets_403(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson('/api/knowledge-packs/ingest-check', [
            'title' => 'Clean pack',
            'summary' => 'n/a',
            'payloads' => [],
        ]);

        $response->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=KnowledgePackIngestCheckControllerTest`
Expected: FAIL — endpoint doesn't exist yet.

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/KnowledgePackController.php`, add the `InboundMnpiGate` dependency and a new method:

```php
    public function __construct(
        private readonly ObservationPackGenerator $generator,
        private readonly \App\Services\InboundMnpiGate $gate,
    ) {
    }
```

(replacing the existing single-dependency constructor), and add:

```php
    public function ingestCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'summary' => 'required|string',
            'payloads' => 'nullable|array',
        ]);

        $result = $this->gate->screen($validated);

        return response()->json($result);
    }
```

- [ ] **Step 4: Wire the route**

In `routes/api.php`, inside the existing `Route::middleware('operator')->group(...)` block, add:

```php
        Route::post('/knowledge-packs/ingest-check', [KnowledgePackController::class, 'ingestCheck']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=KnowledgePackIngestCheckControllerTest`
Expected: PASS (all 5 tests)

- [ ] **Step 6: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS, 0 failures.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/KnowledgePackController.php routes/api.php tests/Feature/KnowledgePackIngestCheckControllerTest.php
git commit -m "feat(compliance-gate): add POST /api/knowledge-packs/ingest-check endpoint"
```

---

### Task 4: Manual end-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Start the backend dev server**

`cd backend && php artisan serve`

- [ ] **Step 2: Create an operator token via tinker**

```bash
php artisan tinker --execute="
\$u = App\Models\User::factory()->create(['email' => 'operator-i3@example.com', 'is_platform_operator' => true]);
echo \$u->createToken('ops')->plainTextToken;
"
```

- [ ] **Step 3: Submit a matching pack and confirm rejection**

```bash
curl -s -X POST http://localhost:8000/api/knowledge-packs/ingest-check \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"title": "Kolomela Q3 output update", "summary": "n/a", "payloads": []}'
```

Confirm the response shows `"decision": "reject"` with `"matched_keywords": ["kolomela"]`.

- [ ] **Step 4: Submit a clean pack and confirm pass**

```bash
curl -s -X POST http://localhost:8000/api/knowledge-packs/ingest-check \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"title": "General sentiment analysis", "summary": "Bullish across tracked instruments", "payloads": []}'
```

Confirm `"decision": "pass"`.

- [ ] **Step 5: Confirm the audit log recorded both**

```bash
php artisan tinker --execute="
App\Models\DkpGateDecision::all()->each(fn (\$d) => print(\$d->decision . ': ' . \$d->pack_title . PHP_EOL));
"
```

Confirm both rows appear.

- [ ] **Step 6: Confirm the rejection was logged as the named event**

```bash
tail -20 storage/logs/laravel.log | grep "trading.compliance.gate_rejected"
```

Confirm the log line appears with the matched keyword.

- [ ] **Step 7: Confirm non-operator/unauthenticated rejection (401/403), matching the established pattern from I1/I2.**

- [ ] **Step 8: Stop the dev server. Report results to the user.**

No commit — verification only.
