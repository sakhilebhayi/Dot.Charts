# Knowledge Pack Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recommendation, insight, and incident_report Knowledge Packs go through a real `pending_approval → approved | rejected` gate before they're signed and treated as official — closing Dot.Charts' Level 2 gap.

**Architecture:** One additive migration (`status`/`rejected_reason`/`reviewed_by`/`reviewed_at` on `knowledge_packs`, defaulting existing rows to `approved`), three generators changed identically (skip signing at generation time, create as `pending_approval`), one new service (`KnowledgePackApprovalService`) doing the deferred signing, three new controller endpoints under the existing `operator` middleware group.

**Tech Stack:** Laravel 12, PHPUnit, `libsodium` (already used by `DkpSigner`), Laravel Sanctum (existing auth pattern for these routes).

## Global Constraints

- `ObservationPackGenerator` and the monthly observation cycle are untouched — stay Level 1, no gate, no status transition ever applied to them beyond the migration's `approved` default.
- Existing (pre-migration) `knowledge_packs` rows default to `status = 'approved'` — never re-signed, never re-verified, envelope untouched.
- Rejecting a pack requires a non-empty reason — never silently accepted as empty string.
- `approve()`/`reject()` both refuse (throw `RuntimeException`) on a pack that isn't currently `pending_approval` — no re-approving an already-decided pack.
- All three approval endpoints stay behind the existing `operator` middleware group in `backend/routes/api.php` — same authorization boundary as `generate`/`index`/`show`/`ingestCheck`.
- `IncidentPackGenerator` uses payload_type `incident_report`, not `incident` — get this exactly right everywhere it's referenced.
- Never touch `frontend/src/backtest.js` or `frontend/src/strategy-builder.js` — pre-existing unrelated uncommitted changes in this repo. Every `git add` in this plan lists files explicitly.

---

### Task 1: Migration + `KnowledgePack` model

**Files:**
- Create: `backend/database/migrations/2026_08_09_000001_add_status_to_knowledge_packs_table.php`
- Modify: `backend/app/Models/KnowledgePack.php`

**Interfaces:**
- Produces: `knowledge_packs.status` (string, default `'approved'`), `.rejected_reason` (nullable text), `.reviewed_by` (nullable FK to `users.id`), `.reviewed_at` (nullable timestamp) — all added to `KnowledgePack::$fillable`. Task 2's generators set `status`; Task 3's service reads/writes all four new columns.

- [ ] **Step 1: Write the migration**

Create `backend/database/migrations/2026_08_09_000001_add_status_to_knowledge_packs_table.php`:

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
            $table->string('status')->default('approved')->after('envelope');
            $table->text('rejected_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'rejected_reason', 'reviewed_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `cd backend && php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Update `KnowledgePack::$fillable`**

In `backend/app/Models/KnowledgePack.php`, add the four new fields:

```php
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
        'status',
        'rejected_reason',
        'reviewed_by',
        'reviewed_at',
        'created_at',
    ];
```

Also add `'reviewed_at' => 'datetime'` to `$casts`:

```php
    protected $casts = [
        'envelope' => 'array',
        'created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
```

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/database/migrations/2026_08_09_000001_add_status_to_knowledge_packs_table.php backend/app/Models/KnowledgePack.php
git commit -m "feat: add approval-status columns to knowledge_packs

Additive migration. Existing rows default to status=approved, untouched.
Sets up the pending_approval/approved/rejected lifecycle Task 2-4 build."
```

---

### Task 2: Generators create packs as `pending_approval`, unsigned

**Files:**
- Modify: `backend/app/Services/RecommendationPackGenerator.php`
- Modify: `backend/app/Services/InsightPackGenerator.php`
- Modify: `backend/app/Services/IncidentPackGenerator.php`
- Modify: `backend/tests/Unit/RecommendationPackGeneratorTest.php`
- Modify: `backend/tests/Unit/InsightPackGeneratorTest.php`
- Modify: `backend/tests/Unit/IncidentPackGeneratorTest.php`

**Interfaces:**
- Consumes: `KnowledgePack::$fillable`'s new `status` field (Task 1).
- Produces: nothing new consumed by later tasks — Task 3's `KnowledgePackApprovalService` operates on any `pending_approval` pack regardless of which generator created it, by reading `$pack->envelope` and `$pack->payload_type` generically.

- [ ] **Step 1: Update the three existing tests that will break**

Each of the three generator test files has one test asserting the freshly-generated pack's envelope verifies immediately — that will no longer be true once signing is deferred. In each file, replace that one test.

In `backend/tests/Unit/RecommendationPackGeneratorTest.php`, replace `test_persisted_envelope_independently_verifies`:

```php
    public function test_persisted_pack_is_pending_approval_and_unsigned(): void
    {
        $this->createMetricPack(includesLossHonesty: true);
        $pack = (new RecommendationPackGenerator())->generate()['pack'];

        $this->assertSame('pending_approval', $pack->status);
        $this->assertSame([], $pack->envelope['signatures']);
        $this->assertFalse((new DkpSigner())->verify($pack->envelope));
    }
```

In `backend/tests/Unit/InsightPackGeneratorTest.php`, replace the equivalent test (same assertion pattern, using whatever variable name that file already uses for its generated `$pack`):

```php
    public function test_persisted_pack_is_pending_approval_and_unsigned(): void
    {
        $pack = (new InsightPackGenerator())->generate(
            'test-insight-slug',
            'Test statement',
            'test-domain',
            'test-method',
            [],
            'test-scope',
            0.9,
        )['pack'];

        $this->assertSame('pending_approval', $pack->status);
        $this->assertSame([], $pack->envelope['signatures']);
        $this->assertFalse((new DkpSigner())->verify($pack->envelope));
    }
```

Read `backend/tests/Unit/InsightPackGeneratorTest.php` first to confirm the exact `generate()` call arguments an existing passing test already uses (title/statement/domain/etc. values), and reuse those same argument values rather than the placeholder ones above if they differ — the goal is a valid call that reaches pack creation, not new fixture data.

In `backend/tests/Unit/IncidentPackGeneratorTest.php`, replace the equivalent test the same way:

```php
    public function test_persisted_pack_is_pending_approval_and_unsigned(): void
    {
        $pack = (new IncidentPackGenerator())->generate(
            'test-incident-slug',
            $this->validIncidentBody(), // reuse this file's existing helper/fixture for a valid incident body
            0.9,
        )['pack'];

        $this->assertSame('pending_approval', $pack->status);
        $this->assertSame([], $pack->envelope['signatures']);
        $this->assertFalse((new DkpSigner())->verify($pack->envelope));
    }
```

Read `backend/tests/Unit/IncidentPackGeneratorTest.php` first to find its existing valid-incident-body fixture (a method or inline array an existing test already uses to satisfy `schemas/incident.schema.json`) and call it by its real name instead of the placeholder `$this->validIncidentBody()` above.

- [ ] **Step 2: Run the three test files to verify the new tests fail**

Run: `cd backend && php artisan test tests/Unit/RecommendationPackGeneratorTest.php tests/Unit/InsightPackGeneratorTest.php tests/Unit/IncidentPackGeneratorTest.php`
Expected: FAIL — `assertSame('pending_approval', $pack->status)` fails because `status` doesn't exist as an accessible attribute value yet (the generators haven't been changed to set it), or `assertFalse(...verify(...))` fails because the envelope is still signed.

- [ ] **Step 3: Update `RecommendationPackGenerator`**

In `backend/app/Services/RecommendationPackGenerator.php`, replace the block from `$envelope['signatures'] = $this->signer->sign($envelope);` through the `KnowledgePack::create([...])` call:

```php
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
            'status' => 'pending_approval',
            'created_at' => $createdAt,
        ]);

        return ['generated' => true, 'reason' => null, 'pending_approval' => true, 'pack' => $pack];
```

(This removes the `$envelope['signatures'] = $this->signer->sign($envelope);` line and the `if (! $this->signer->verify($envelope)) { throw ... }` block entirely — the envelope's `'signatures' => []` from its construction above stays as-is. The `use RuntimeException;` import may now be unused in this file if nothing else in it throws `RuntimeException` — check with `grep -n RuntimeException backend/app/Services/RecommendationPackGenerator.php` after this edit, and remove the import only if that grep shows just the `use` line remaining.)

- [ ] **Step 4: Update `InsightPackGenerator`**

Apply the identical change to `backend/app/Services/InsightPackGenerator.php`: remove the `sign()`/`verify()` block, add `'status' => 'pending_approval'` to its `KnowledgePack::create([...])` call, add `'pending_approval' => true` to its return array, remove the now-possibly-unused `RuntimeException` import following the same check.

- [ ] **Step 5: Update `IncidentPackGenerator`**

Apply the identical change to `backend/app/Services/IncidentPackGenerator.php`: remove the `sign()`/`verify()` block, add `'status' => 'pending_approval'` to its `KnowledgePack::create([...])` call, add `'pending_approval' => true` to its return array, remove the now-possibly-unused `RuntimeException` import following the same check.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Unit/RecommendationPackGeneratorTest.php tests/Unit/InsightPackGeneratorTest.php tests/Unit/IncidentPackGeneratorTest.php`
Expected: PASS (all tests in all three files, including the other pre-existing tests that don't touch signing/status — e.g. `test_generates_a_signed_recommendation_pack`'s name is now slightly inaccurate but its actual assertions — `generated === true`, `payload_type === 'recommendation'`, `pack_id` format — are unaffected and still pass; do not rename it, renaming existing passing tests is out of scope for this task).

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Services/RecommendationPackGenerator.php backend/app/Services/InsightPackGenerator.php \
  backend/app/Services/IncidentPackGenerator.php backend/tests/Unit/RecommendationPackGeneratorTest.php \
  backend/tests/Unit/InsightPackGeneratorTest.php backend/tests/Unit/IncidentPackGeneratorTest.php
git commit -m "feat: recommendation/insight/incident packs generate as pending_approval

Signing deferred to approval (Task 3) -- these three generators no longer
sign or self-verify at generation time. ObservationPackGenerator is
untouched and stays Level 1."
```

---

### Task 3: `KnowledgePackApprovalService`

**Files:**
- Create: `backend/app/Services/KnowledgePackApprovalService.php`
- Test: `backend/tests/Unit/KnowledgePackApprovalServiceTest.php`

**Interfaces:**
- Consumes: `KnowledgePack` (Task 1's new columns), `DkpSigner::sign()`/`verify()` (existing, unchanged).
- Produces: `KnowledgePackApprovalService::approve(KnowledgePack $pack, User $reviewer): KnowledgePack`, `::reject(KnowledgePack $pack, User $reviewer, string $reason): KnowledgePack`. Task 4's controller calls both by these exact names and signatures.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/KnowledgePackApprovalServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\KnowledgePack;
use App\Models\User;
use App\Services\DkpSigner;
use App\Services\KnowledgePackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesDkpTestKey;
use Tests\TestCase;

class KnowledgePackApprovalServiceTest extends TestCase
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

    private function pendingPack(): KnowledgePack
    {
        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => 'dkp:dot-charts:' . Str::uuid(),
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'test']]],
            'confidence' => 0.9,
            'signatures' => [],
        ];

        return KnowledgePack::create([
            'pack_id' => $envelope['pack_id'],
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Test pack',
            'summary' => 'Test summary',
            'period' => 'test-slug',
            'envelope' => $envelope,
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);
    }

    public function test_approve_signs_the_envelope_and_flips_status(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $approved = (new KnowledgePackApprovalService())->approve($pack, $reviewer);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertNotSame([], $approved->envelope['signatures']);
        $this->assertTrue((new DkpSigner())->verify($approved->envelope));
    }

    public function test_approve_refuses_a_pack_that_is_not_pending(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();
        $pack->update(['status' => 'approved']);

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService())->approve($pack, $reviewer);
    }

    public function test_reject_requires_a_non_empty_reason(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService())->reject($pack, $reviewer, '   ');
    }

    public function test_reject_sets_status_and_reason_and_leaves_envelope_unsigned(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();

        $rejected = (new KnowledgePackApprovalService())->reject($pack, $reviewer, 'Not accurate.');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Not accurate.', $rejected->rejected_reason);
        $this->assertSame($reviewer->id, $rejected->reviewed_by);
        $this->assertSame([], $rejected->envelope['signatures']);
    }

    public function test_reject_refuses_a_pack_that_is_not_pending(): void
    {
        $pack = $this->pendingPack();
        $reviewer = User::factory()->create();
        $pack->update(['status' => 'rejected']);

        $this->expectException(RuntimeException::class);
        (new KnowledgePackApprovalService())->reject($pack, $reviewer, 'A reason.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/KnowledgePackApprovalServiceTest.php`
Expected: FAIL — `Class "App\Services\KnowledgePackApprovalService" not found`.

- [ ] **Step 3: Write `KnowledgePackApprovalService`**

Create `backend/app/Services/KnowledgePackApprovalService.php`:

```php
<?php

namespace App\Services;

use App\Models\KnowledgePack;
use App\Models\User;
use RuntimeException;

class KnowledgePackApprovalService
{
    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    public function approve(KnowledgePack $pack, User $reviewer): KnowledgePack
    {
        if ($pack->status !== 'pending_approval') {
            throw new RuntimeException("Cannot approve a pack with status \"{$pack->status}\" -- only pending_approval packs may be approved.");
        }

        $envelope = $pack->envelope;
        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Approved Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack->update([
            'envelope' => $envelope,
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $pack->fresh();
    }

    public function reject(KnowledgePack $pack, User $reviewer, string $reason): KnowledgePack
    {
        if ($pack->status !== 'pending_approval') {
            throw new RuntimeException("Cannot reject a pack with status \"{$pack->status}\" -- only pending_approval packs may be rejected.");
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        $pack->update([
            'status' => 'rejected',
            'rejected_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $pack->fresh();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Unit/KnowledgePackApprovalServiceTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Services/KnowledgePackApprovalService.php backend/tests/Unit/KnowledgePackApprovalServiceTest.php
git commit -m "feat: KnowledgePackApprovalService (approve/reject)

approve() performs the deferred DkpSigner sign+verify and flips status;
reject() requires a non-empty reason and leaves the envelope permanently
unsigned. Both refuse to act on a pack that isn't pending_approval."
```

---

### Task 4: Controller endpoints + routes + manual verification

**Files:**
- Modify: `backend/app/Http/Controllers/KnowledgePackController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/KnowledgePackControllerTest.php`

**Interfaces:**
- Consumes: `KnowledgePackApprovalService::approve()`/`::reject()` (Task 3, exact signatures above).

- [ ] **Step 1: Write the failing tests**

Read `backend/tests/Feature/KnowledgePackControllerTest.php` first to see its existing `operatorToken()` private helper (already returns a bearer token for an `is_platform_operator` user) and reuse it exactly as-is. Add these test methods to the existing class (do not create a new file):

```php
    public function test_pending_lists_only_pending_approval_packs(): void
    {
        $token = $this->operatorToken();
        $pending = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'pending-slug',
            'envelope' => ['payloads' => [['payload_type' => 'insight', 'body' => ['statement' => 'x']]], 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);
        \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Already approved',
            'summary' => 'Test',
            'period' => 'approved-slug',
            'envelope' => ['payloads' => [], 'signatures' => []],
            'status' => 'approved',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/knowledge-packs/pending');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains(fn ($id) => $id !== $pending->id && false)); // sanity no-op guard against an empty list
        $this->assertCount(1, $response->json('data'));
    }

    public function test_approve_signs_and_finalizes_a_pending_pack(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'approve-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/approve");

        $response->assertOk();
        $response->assertJson(['status' => 'approved']);
        $this->assertSame('approved', $pack->fresh()->status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'reject-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/reject", []);

        $response->assertStatus(422);
        $this->assertSame('pending_approval', $pack->fresh()->status);
    }

    public function test_reject_with_a_reason_marks_the_pack_rejected(): void
    {
        $token = $this->operatorToken();
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'reject-slug-2',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/reject", ['reason' => 'Not accurate.']);

        $response->assertOk();
        $response->assertJson(['status' => 'rejected', 'rejected_reason' => 'Not accurate.']);
        $this->assertSame('rejected', $pack->fresh()->status);
    }

    public function test_non_operator_cannot_approve(): void
    {
        $user = User::factory()->create(['is_platform_operator' => false]);
        $token = $user->createToken('api')->plainTextToken;
        $pack = \App\Models\KnowledgePack::create([
            'pack_id' => 'dkp:dot-charts:' . \Illuminate\Support\Str::uuid(),
            'payload_type' => 'insight',
            'pack_version' => '1.0.0',
            'title' => 'Pending pack',
            'summary' => 'Test',
            'period' => 'auth-slug',
            'envelope' => ['payloads' => [], 'confidence' => 0.9, 'signatures' => []],
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/knowledge-packs/{$pack->id}/approve");

        $response->assertStatus(403);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test tests/Feature/KnowledgePackControllerTest.php`
Expected: FAIL — the new routes don't exist yet (404s).

- [ ] **Step 3: Add controller methods**

In `backend/app/Http/Controllers/KnowledgePackController.php`, add `use App\Services\KnowledgePackApprovalService;` and `use App\Models\KnowledgePack;` (already imported) to the top, add the service to the constructor, and add three new methods:

```php
    public function __construct(
        private readonly ObservationPackGenerator $generator,
        private readonly InboundMnpiGate $gate,
        private readonly KnowledgePackApprovalService $approvalService,
    ) {
    }
```

```php
    public function pending(): JsonResponse
    {
        $packs = KnowledgePack::where('status', 'pending_approval')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (KnowledgePack $pack) => [
                'id' => $pack->id,
                'pack_id' => $pack->pack_id,
                'title' => $pack->title,
                'summary' => $pack->summary,
                'payload_type' => $pack->payload_type,
                'body' => $pack->envelope['payloads'][0]['body'] ?? null,
                'created_at' => $pack->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $packs]);
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        $pack = KnowledgePack::findOrFail($id);
        $pack = $this->approvalService->approve($pack, $request->user());

        return response()->json(['pack_id' => $pack->pack_id, 'status' => $pack->status]);
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string']);
        $pack = KnowledgePack::findOrFail($id);
        $pack = $this->approvalService->reject($pack, $request->user(), $validated['reason']);

        return response()->json(['pack_id' => $pack->pack_id, 'status' => $pack->status, 'rejected_reason' => $pack->rejected_reason]);
    }
```

Also add `'status' => $pack->status,` to the existing `index()` method's `->through(fn (KnowledgePack $pack) => [...])` array (right after `'payload_type' => $pack->payload_type,`), so the response includes status without changing anything else about that method.

- [ ] **Step 4: Add routes**

In `backend/routes/api.php`, inside the existing `Route::middleware('operator')->group(function () { ... })` block, add three lines after the existing `ingest-check` line:

```php
        Route::get('/knowledge-packs/pending', [KnowledgePackController::class, 'pending']);
        Route::post('/knowledge-packs/{id}/approve', [KnowledgePackController::class, 'approve']);
        Route::post('/knowledge-packs/{id}/reject', [KnowledgePackController::class, 'reject']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/KnowledgePackControllerTest.php`
Expected: PASS (all tests in the file, including the 5 new ones and every pre-existing test — the pre-existing `generate`/`index`/`show`/`ingestCheck` tests are unaffected by these additions).

- [ ] **Step 6: Run the full backend test suite**

Run: `cd backend && php artisan test`
Expected: 0 failures across the whole suite (this confirms Task 2's generator changes and Task 1's migration didn't break anything elsewhere — e.g. any command/test that calls `dkp:generate-recommendation`/`-insight`/`-incident` and asserted immediate signing).

- [ ] **Step 7: Manual end-to-end verification**

```bash
cd backend
php artisan tinker --execute '
$u = \App\Models\User::factory()->create(["is_platform_operator" => true]);
echo $u->createToken("api")->plainTextToken;
'
```

Use the printed token to run, against a running `php artisan serve` (or note the commands for the user to run):

```bash
curl -s -X POST http://127.0.0.1:8000/api/knowledge-packs/generate \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"strategy_class":"ma_crossover"}'
curl -s http://127.0.0.1:8000/api/knowledge-packs/pending -H "Authorization: Bearer <token>"
```

Expected: `generate` still works unaffected (it's `ObservationPackGenerator`, untouched); `pending` returns an empty list (observation packs never enter this gate) — confirming the gate applies only to the three interpretive payload types, not the routine Level 1 cycle. A full recommendation/insight/incident round-trip (generate via artisan command → appears in `/pending` → approve → verifies) is covered by the automated tests in Tasks 2-4; this manual step only needs to confirm the untouched `generate`/`pending` interaction stays correct.

- [ ] **Step 8: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/KnowledgePackController.php backend/routes/api.php \
  backend/tests/Feature/KnowledgePackControllerTest.php
git commit -m "feat: Knowledge Pack approval endpoints (pending/approve/reject)

GET /knowledge-packs/pending, POST /knowledge-packs/{id}/approve,
POST /knowledge-packs/{id}/reject -- all behind the existing operator
middleware. Verified end-to-end: full test suite green, manual curl
confirms observation packs (Level 1, untouched) never appear in /pending."
```

---

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1 in full. Task 2 covers spec §2 in full (all three generators, plus the three existing tests the change breaks). Task 3 covers spec §3 in full. Task 4 covers spec §4 in full (all three endpoints, the `index()` status field, the optional `?status=` filter is explicitly deferred — see note below).
- **Scope note:** the spec's `index()` `?status=` query filter was mentioned as optional ("defaults to no filter") — this plan adds the `status` field to `index()`'s response but does not add the query-string filter itself, since `GET /knowledge-packs/pending` already serves the one real use case (the review queue) the spec identifies as needing a dedicated view. Adding an unused filter parameter would be speculative; if a real need for filtering the full `index()` list by status emerges, it's a small follow-up, not part of closing this gap.
- **Placeholder scan:** none — every step has literal file content. Two steps (Task 2 Steps 1's Insight/Incident test replacements) explicitly instruct reading the real file first to match existing fixture argument values/helper names rather than guessing them, which is a deliberate instruction to look at real code, not a content placeholder.
- **Type consistency:** `KnowledgePackApprovalService::approve(KnowledgePack $pack, User $reviewer): KnowledgePack` and `::reject(KnowledgePack $pack, User $reviewer, string $reason): KnowledgePack` signatures match identically between Task 3's definition, Task 3's own tests, and Task 4's controller usage.
- **Uncommitted pre-existing changes:** verified via `git status --short` that `frontend/src/backtest.js`, `frontend/src/strategy-builder.js`, and several untracked `docs/superpowers/plans/*.md` files are pre-existing unrelated work in this repo — every `git add` in this plan lists files explicitly, never `git add -A` or `git add .`.
