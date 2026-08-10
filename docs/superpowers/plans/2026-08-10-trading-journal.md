# Trading Journal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a trading journal to Dot.Charts — reflective notes optionally linked to a `BacktestRun` or `CustomStrategy`, never a real position/order/P&L record — per the approved design at [docs/superpowers/specs/2026-08-10-trading-journal-design.md](../specs/2026-08-10-trading-journal-design.md).

**Architecture:** One new table (`journal_entries`) and one new owner-scoped CRUD controller (`JournalEntryController`), following `CustomStrategyController`'s exact conventions (Sanctum auth, `firstOrFail` scoped by `user_id`, 404 not 403 on another user's resource). One new frontend page (`journal.html` + `journal.js`) following `history.html`/`history.js`'s exact structure (auth-state header, paginated list, `load more`), plus a small addition to `history.html`'s existing row template.

**Tech Stack:** Laravel 12 (backend/), Sanctum token auth, PHPUnit (RefreshDatabase), vanilla JS + Vite (frontend/), no frontend test framework (matches existing project convention — frontend verification is manual/browser).

## Global Constraints

- Never add a position/order/P&L-shaped column to `journal_entries` or any table this feature touches — this is the platform's standing, deliberate regulated-market invariant (wiki.md §7, `platforms/dot-charts.md` §2 in `~/Dot/Dot.Brain`), not a style preference. Task 2 is the automated proof of this; do not weaken or remove it to make a later task easier.
- All new backend routes live under the existing `auth:sanctum` middleware group in `backend/routes/api.php`, alongside `/backtests` and `/strategies` — no anonymous access to journal entries.
- Ownership on `backtest_run_id`/`custom_strategy_id` is checked explicitly (`where('user_id', ...)->exists()`), never via a bare `exists:table,id` Laravel validation rule — that rule alone would accept any user's row, not just the caller's.
- Match existing code style exactly: run `./vendor/bin/pint` on every touched PHP file before committing it (this repo's Pint config is opinionated — e.g. `new ClassName` not `new ClassName()` for zero-arg construction).
- Every task's PHP work ends with `php artisan test` passing in full (not just the new file) — this repo has 171 existing passing tests as of this plan; regressions are not acceptable collateral.

---

### Task 1: `journal_entries` migration, `JournalEntry` model, factory, and model test

**Files:**
- Create: `backend/database/migrations/2026_08_10_000001_create_journal_entries_table.php`
- Create: `backend/app/Models/JournalEntry.php`
- Create: `backend/database/factories/JournalEntryFactory.php`
- Test: `backend/tests/Unit/JournalEntryModelTest.php`

**Interfaces:**
- Produces: `App\Models\JournalEntry` with fillable `user_id`, `title`, `body`, `symbol`, `backtest_run_id`, `custom_strategy_id`; relations `user()`, `backtestRun()`, `customStrategy()` (all `BelongsTo`). Table `journal_entries` with columns `id, user_id, title, body, symbol, backtest_run_id, custom_strategy_id, created_at, updated_at`. `JournalEntry::factory()` available for later tasks' tests.

- [ ] **Step 1: Write the failing model test**

Create `backend/tests/Unit/JournalEntryModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standalone_entry_persists_with_no_links(): void
    {
        $user = User::factory()->create();

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'First reflection',
            'body' => 'Just some notes, not linked to anything.',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'user_id' => $user->id,
            'title' => 'First reflection',
            'backtest_run_id' => null,
            'custom_strategy_id' => null,
        ]);
        $this->assertTrue($entry->user->is($user));
        $this->assertNull($entry->backtestRun);
        $this->assertNull($entry->customStrategy);
    }

    public function test_an_entry_can_link_to_a_backtest_run_and_a_custom_strategy(): void
    {
        $user = User::factory()->create();
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Linked reflection',
            'body' => 'This one references a real backtest and strategy.',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);

        $this->assertTrue($entry->backtestRun->is($run));
        $this->assertTrue($entry->customStrategy->is($strategy));
    }

    public function test_deleting_a_linked_backtest_run_nulls_the_link_not_the_entry(): void
    {
        $user = User::factory()->create();
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Reflection',
            'body' => 'Body text.',
            'backtest_run_id' => $run->id,
        ]);

        $run->delete();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'backtest_run_id' => null]);
    }

    public function test_deleting_a_linked_custom_strategy_nulls_the_link_not_the_entry(): void
    {
        $user = User::factory()->create();
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Reflection',
            'body' => 'Body text.',
            'custom_strategy_id' => $strategy->id,
        ]);

        $strategy->delete();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'custom_strategy_id' => null]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=JournalEntryModelTest`
Expected: FAIL — `Class "App\Models\JournalEntry" not found` (neither the model nor the migration exist yet).

- [ ] **Step 3: Create the migration**

Create `backend/database/migrations/2026_08_10_000001_create_journal_entries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('symbol')->nullable();
            // nullOnDelete, not cascadeOnDelete: a journal entry is a
            // reflection that should outlive the backtest/strategy it
            // references, not disappear with it.
            $table->foreignId('backtest_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('custom_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
```

- [ ] **Step 4: Create the model**

Create `backend/app/Models/JournalEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'symbol',
        'backtest_run_id',
        'custom_strategy_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }

    public function customStrategy(): BelongsTo
    {
        return $this->belongsTo(CustomStrategy::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `backend/database/factories/JournalEntryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Reviewing the EMA crossover backtest',
            'body' => 'Noted the strategy performed better in trending conditions than choppy ones.',
            'symbol' => null,
            'backtest_run_id' => null,
            'custom_strategy_id' => null,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=JournalEntryModelTest`
Expected: PASS — 4 tests.

- [ ] **Step 7: Pint and full suite**

Run:
```bash
cd backend
./vendor/bin/pint app/Models/JournalEntry.php database/factories/JournalEntryFactory.php database/migrations/2026_08_10_000001_create_journal_entries_table.php tests/Unit/JournalEntryModelTest.php
php artisan test
```
Expected: Pint reports no changes needed (or auto-fixes cleanly); full suite passes (175 tests: 171 existing + 4 new).

- [ ] **Step 8: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/database/migrations/2026_08_10_000001_create_journal_entries_table.php backend/app/Models/JournalEntry.php backend/database/factories/JournalEntryFactory.php backend/tests/Unit/JournalEntryModelTest.php
git commit -m "feat(journal): add journal_entries table, model, and factory

nullOnDelete on both optional FKs (backtest_run_id, custom_strategy_id)
-- a journal entry should survive its linked backtest/strategy being
deleted, just losing the link, not cascade-delete with it.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Schema-invariant regression test

**Files:**
- Test: `backend/tests/Unit/JournalEntriesSchemaInvariantTest.php`

**Interfaces:**
- Consumes: the `journal_entries` table from Task 1.
- Produces: nothing new consumed by later tasks — this is a standalone regression guard.

- [ ] **Step 1: Write the test**

Create `backend/tests/Unit/JournalEntriesSchemaInvariantTest.php`:

```php
<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The actual enforcement mechanism behind wiki.md's "position/order
 * exclusion enforced at the schema level" claim for the trading journal --
 * not just a comment saying so. Fails the moment anyone adds a
 * position/order/P&L-shaped column to journal_entries, deliberately or by
 * accident, without an explicit, informed decision to override this
 * platform's standing regulated-market invariant. See
 * docs/superpowers/specs/2026-08-10-trading-journal-design.md and
 * platforms/dot-charts.md §2 in ~/Dot/Dot.Brain for why this exists.
 */
class JournalEntriesSchemaInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const BANNED_COLUMN_NAMES = [
        'quantity', 'qty', 'price', 'entry_price', 'exit_price', 'fill_price',
        'position_size', 'position', 'side', 'direction', 'order_type',
        'leverage', 'pnl', 'profit_loss', 'realized_pnl', 'unrealized_pnl',
        'stop_loss', 'take_profit', 'margin', 'lot_size', 'volume',
    ];

    public function test_journal_entries_never_gains_a_position_or_order_shaped_column(): void
    {
        $columns = Schema::getColumnListing('journal_entries');

        $bannedFound = array_intersect(array_map('strtolower', $columns), self::BANNED_COLUMN_NAMES);

        $this->assertEmpty(
            $bannedFound,
            'journal_entries has gained a position/order-shaped column ('.implode(', ', $bannedFound).') '
                .'-- this violates Dot.Charts\' standing, deliberate never-persist-positions invariant '
                .'(wiki.md §7, platforms/dot-charts.md §2). Not a bug to silently fix -- needs an explicit, '
                .'informed ecosystem-level decision before proceeding.'
        );
    }

    public function test_journal_entries_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('journal_entries');
        sort($columns);

        $this->assertSame(
            ['backtest_run_id', 'body', 'created_at', 'custom_strategy_id', 'id', 'symbol', 'title', 'updated_at', 'user_id'],
            $columns
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it passes immediately**

Run: `cd backend && php artisan test --filter=JournalEntriesSchemaInvariantTest`
Expected: PASS — 2 tests. (This test has no "make it fail first" step in the usual TDD sense — it's a regression guard against a schema that already exists correctly from Task 1. Confirming it passes now is what proves it will correctly fail later if the invariant is ever violated.)

- [ ] **Step 3: Pint and full suite**

```bash
cd backend
./vendor/bin/pint tests/Unit/JournalEntriesSchemaInvariantTest.php
php artisan test
```
Expected: full suite passes (177 tests).

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/tests/Unit/JournalEntriesSchemaInvariantTest.php
git commit -m "test(journal): add schema-invariant regression guard

The actual mechanism proving the roadmap item's 'position/order
exclusion enforced at the schema level' claim -- fails the build if
journal_entries ever gains a position/order/P&L-shaped column.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: `JournalEntryController::store` + route + tests

**Files:**
- Create: `backend/app/Http/Controllers/JournalEntryController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/JournalEntryControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\JournalEntry` (Task 1), `App\Models\BacktestRun`, `App\Models\CustomStrategy` (existing).
- Produces: `POST /api/journal-entries` (Sanctum-authenticated). Private helper `ownedLinkIsValid(?int $id, string $modelClass, int $userId): bool` on the controller, reused by Task 5's `update()`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/JournalEntryControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_a_standalone_entry_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'My first reflection',
            'body' => 'Notes on the market today.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'My first reflection');
        $this->assertDatabaseHas('journal_entries', ['title' => 'My first reflection', 'user_id' => $user->id]);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/journal-entries', ['title' => 'X', 'body' => 'Y']);

        $response->assertStatus(401);
    }

    public function test_store_requires_title_and_body(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_store_links_to_the_users_own_backtest_run_and_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Linked entry',
            'body' => 'Reflecting on this specific run.',
            'symbol' => 'AAPL',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('journal_entries', [
            'title' => 'Linked entry',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);
    }

    public function test_store_rejects_linking_to_another_users_backtest_run(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $othersRun = BacktestRun::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Sneaky entry',
            'body' => 'Trying to link to a run I do not own.',
            'backtest_run_id' => $othersRun->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('journal_entries', ['title' => 'Sneaky entry']);
    }

    public function test_store_rejects_linking_to_another_users_custom_strategy(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $othersStrategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Sneaky entry',
            'body' => 'Trying to link to a strategy I do not own.',
            'custom_strategy_id' => $othersStrategy->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('journal_entries', ['title' => 'Sneaky entry']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: FAIL — route `/api/journal-entries` doesn't exist (404 on every request).

- [ ] **Step 3: Create the controller**

Create `backend/app/Http/Controllers/JournalEntryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'symbol' => 'nullable|string|max:20',
            'backtest_run_id' => 'nullable|integer',
            'custom_strategy_id' => 'nullable|integer',
        ]);

        $userId = $request->user('sanctum')->id;

        if (! $this->ownedLinkIsValid($validated['backtest_run_id'] ?? null, BacktestRun::class, $userId)) {
            return response()->json(['error' => 'The selected backtest run does not exist or does not belong to you.'], 422);
        }

        if (! $this->ownedLinkIsValid($validated['custom_strategy_id'] ?? null, CustomStrategy::class, $userId)) {
            return response()->json(['error' => 'The selected strategy does not exist or does not belong to you.'], 422);
        }

        $entry = JournalEntry::create([
            'user_id' => $userId,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'symbol' => $validated['symbol'] ?? null,
            'backtest_run_id' => $validated['backtest_run_id'] ?? null,
            'custom_strategy_id' => $validated['custom_strategy_id'] ?? null,
        ]);

        return response()->json($entry, 201);
    }

    /**
     * A bare 'exists:table,id' Laravel validation rule would accept ANY
     * user's row, not just the caller's -- this checks ownership
     * explicitly. Reused by update() in Task 5.
     */
    private function ownedLinkIsValid(?int $id, string $modelClass, int $userId): bool
    {
        if ($id === null) {
            return true;
        }

        return $modelClass::where('id', $id)->where('user_id', $userId)->exists();
    }
}
```

- [ ] **Step 4: Wire the route**

Modify `backend/routes/api.php`. Add the import alongside the existing controller imports:

```diff
 use App\Http\Controllers\AuthController;
 use App\Http\Controllers\BacktestController;
 use App\Http\Controllers\ChartAnalysisController;
 use App\Http\Controllers\CustomStrategyController;
+use App\Http\Controllers\JournalEntryController;
 use App\Http\Controllers\KnowledgePackController;
 use Illuminate\Support\Facades\Route;
```

Add the route inside the `auth:sanctum` group, after the `/strategies` routes and before the nested `operator` group:

```diff
     Route::delete('/strategies/{id}', [CustomStrategyController::class, 'destroy']);
 
+    Route::post('/journal-entries', [JournalEntryController::class, 'store']);
+
     Route::middleware('operator')->group(function () {
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: PASS — 6 tests.

- [ ] **Step 6: Pint and full suite**

```bash
cd backend
./vendor/bin/pint app/Http/Controllers/JournalEntryController.php routes/api.php tests/Feature/JournalEntryControllerTest.php
php artisan test
```
Expected: full suite passes (183 tests).

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/JournalEntryController.php backend/routes/api.php backend/tests/Feature/JournalEntryControllerTest.php
git commit -m "feat(journal): add POST /api/journal-entries with ownership-validated links

Linking to a backtest_run_id/custom_strategy_id that doesn't belong to
the authenticated user is rejected with 422 -- a bare exists: rule
would have accepted any user's row.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: `JournalEntryController::index` (pagination + filters) + route + tests

**Files:**
- Modify: `backend/app/Http/Controllers/JournalEntryController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/JournalEntryControllerTest.php`

**Interfaces:**
- Consumes: `JournalEntryController` from Task 3.
- Produces: `GET /api/journal-entries` (Sanctum-authenticated), paginated, `?symbol=`/`?backtest_run_id=`/`?custom_strategy_id=` filters.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/JournalEntryControllerTest.php` (inside the class, after the existing `store` tests):

```php
    public function test_index_returns_only_the_authenticated_users_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Mine']);
        JournalEntry::factory()->create(['user_id' => $otherUser->id, 'title' => 'Not Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Not Mine'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/journal-entries');

        $response->assertStatus(401);
    }

    public function test_index_paginates_at_twenty(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->count(21)->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries');

        $response->assertOk();
        $this->assertCount(20, $response->json('data'));
        $this->assertNotNull($response->json('next_page_url'));
    }

    public function test_index_filters_by_symbol(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'AAPL note', 'symbol' => 'AAPL']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'BTC note', 'symbol' => 'BTC/USDT']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries?symbol=AAPL');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('AAPL note'));
        $this->assertFalse($titles->contains('BTC note'));
    }

    public function test_index_filters_by_backtest_run_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Linked', 'backtest_run_id' => $run->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Unlinked']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries?backtest_run_id={$run->id}");

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Linked'));
        $this->assertFalse($titles->contains('Unlinked'));
    }

    public function test_index_filters_by_custom_strategy_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Linked', 'custom_strategy_id' => $strategy->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Unlinked']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries?custom_strategy_id={$strategy->id}");

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Linked'));
        $this->assertFalse($titles->contains('Unlinked'));
    }
```

Add `use App\Models\JournalEntry;` to the test file's imports (it's already using `BacktestRun`, `CustomStrategy`, `User`).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: FAIL on all 6 new tests, including `test_index_requires_authentication` — a route that doesn't exist returns `404` from Laravel's router, not `401`, so that assertion fails too (not a coincidental pass).

- [ ] **Step 3: Add `index()` to the controller**

Modify `backend/app/Http/Controllers/JournalEntryController.php` — add this method after `store()`:

```php
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user('sanctum')->id;

        $query = JournalEntry::where('user_id', $userId)->orderByDesc('created_at');

        if ($request->filled('symbol')) {
            $query->where('symbol', $request->query('symbol'));
        }
        if ($request->filled('backtest_run_id')) {
            $query->where('backtest_run_id', $request->query('backtest_run_id'));
        }
        if ($request->filled('custom_strategy_id')) {
            $query->where('custom_strategy_id', $request->query('custom_strategy_id'));
        }

        $entries = $query->paginate(20)->appends($request->query());

        return response()->json($entries);
    }
```

- [ ] **Step 4: Wire the route**

Modify `backend/routes/api.php`:

```diff
     Route::post('/journal-entries', [JournalEntryController::class, 'store']);
+    Route::get('/journal-entries', [JournalEntryController::class, 'index']);
 
     Route::middleware('operator')->group(function () {
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: PASS — 12 tests total in this file.

- [ ] **Step 6: Pint and full suite**

```bash
cd backend
./vendor/bin/pint app/Http/Controllers/JournalEntryController.php routes/api.php tests/Feature/JournalEntryControllerTest.php
php artisan test
```
Expected: full suite passes (189 tests).

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/JournalEntryController.php backend/routes/api.php backend/tests/Feature/JournalEntryControllerTest.php
git commit -m "feat(journal): add GET /api/journal-entries with pagination and filters

Paginated at 20 (matching /api/backtests and /api/strategies),
optional symbol/backtest_run_id/custom_strategy_id filters.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: `JournalEntryController::show`/`update`/`destroy` + routes + tests

**Files:**
- Modify: `backend/app/Http/Controllers/JournalEntryController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/JournalEntryControllerTest.php`

**Interfaces:**
- Consumes: `JournalEntryController::ownedLinkIsValid()` from Task 3.
- Produces: `GET /api/journal-entries/{id}`, `PATCH /api/journal-entries/{id}`, `DELETE /api/journal-entries/{id}`.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/JournalEntryControllerTest.php`:

```php
    public function test_show_returns_an_owned_entry(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries/{$entry->id}");

        $response->assertOk();
        $response->assertJsonPath('title', 'Mine');
    }

    public function test_show_returns_404_for_another_users_entry(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries/{$entry->id}");

        $response->assertStatus(404);
    }

    public function test_update_changes_title_and_body_on_an_owned_entry(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Old title']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->patchJson("/api/journal-entries/{$entry->id}", [
            'title' => 'New title',
            'body' => 'Updated body.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('title', 'New title');
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'title' => 'New title']);
    }

    public function test_update_allows_partial_updates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Keep me', 'body' => 'Keep this too']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->patchJson("/api/journal-entries/{$entry->id}", [
            'symbol' => 'AAPL',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'title' => 'Keep me', 'body' => 'Keep this too', 'symbol' => 'AAPL']);
    }

    public function test_update_returns_404_for_another_users_entry(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $otherUser->id, 'title' => 'Not yours']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->patchJson("/api/journal-entries/{$entry->id}", ['title' => 'Hijacked']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'title' => 'Not yours']);
    }

    public function test_update_rejects_relinking_to_another_users_backtest_run(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $user->id]);
        $othersRun = BacktestRun::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->patchJson("/api/journal-entries/{$entry->id}", [
            'backtest_run_id' => $othersRun->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'backtest_run_id' => null]);
    }

    public function test_destroy_removes_an_owned_entry(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/journal-entries/{$entry->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
    }

    public function test_destroy_returns_404_for_another_users_entry_and_does_not_delete_it(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $entry = JournalEntry::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/journal-entries/{$entry->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_show_update_destroy_require_authentication(): void
    {
        $entry = JournalEntry::factory()->create();

        $this->getJson("/api/journal-entries/{$entry->id}")->assertStatus(401);
        $this->patchJson("/api/journal-entries/{$entry->id}", ['title' => 'X'])->assertStatus(401);
        $this->deleteJson("/api/journal-entries/{$entry->id}")->assertStatus(401);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: FAIL — no `show`/`update`/`destroy` routes exist yet.

- [ ] **Step 3: Add `show()`, `update()`, `destroy()` to the controller**

Modify `backend/app/Http/Controllers/JournalEntryController.php` — add these methods after `index()`, before the private `ownedLinkIsValid()` helper:

```php
    public function show(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        return response()->json($entry);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:200',
            'body' => 'sometimes|required|string',
            'symbol' => 'nullable|string|max:20',
            'backtest_run_id' => 'nullable|integer',
            'custom_strategy_id' => 'nullable|integer',
        ]);

        $userId = $request->user('sanctum')->id;

        if (array_key_exists('backtest_run_id', $validated)
            && ! $this->ownedLinkIsValid($validated['backtest_run_id'], BacktestRun::class, $userId)) {
            return response()->json(['error' => 'The selected backtest run does not exist or does not belong to you.'], 422);
        }

        if (array_key_exists('custom_strategy_id', $validated)
            && ! $this->ownedLinkIsValid($validated['custom_strategy_id'], CustomStrategy::class, $userId)) {
            return response()->json(['error' => 'The selected strategy does not exist or does not belong to you.'], 422);
        }

        $entry->update($validated);

        return response()->json($entry->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $entry->delete();

        return response()->json(['success' => true]);
    }
```

- [ ] **Step 4: Wire the routes**

Modify `backend/routes/api.php`:

```diff
     Route::post('/journal-entries', [JournalEntryController::class, 'store']);
     Route::get('/journal-entries', [JournalEntryController::class, 'index']);
+    Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
+    Route::patch('/journal-entries/{id}', [JournalEntryController::class, 'update']);
+    Route::delete('/journal-entries/{id}', [JournalEntryController::class, 'destroy']);
 
     Route::middleware('operator')->group(function () {
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=JournalEntryControllerTest`
Expected: PASS — 21 tests total in this file.

- [ ] **Step 6: Pint and full suite**

```bash
cd backend
./vendor/bin/pint app/Http/Controllers/JournalEntryController.php routes/api.php tests/Feature/JournalEntryControllerTest.php
php artisan test
```
Expected: full suite passes (198 tests).

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/JournalEntryController.php backend/routes/api.php backend/tests/Feature/JournalEntryControllerTest.php
git commit -m "feat(journal): add show/update/destroy, complete the CRUD surface

update() re-validates ownership on backtest_run_id/custom_strategy_id
if either is present in the request -- the same protection store()
has, since re-linking to someone else's row on edit is the same class
of bug as linking to it on create.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: `journal.html` + `journal.js` — list and create

**Files:**
- Create: `frontend/journal.html`
- Create: `frontend/src/journal.js`

**Interfaces:**
- Consumes: `getToken`/`clearToken`/`isLoggedIn` from `frontend/src/auth.js`; `GET /api/journal-entries`, `POST /api/journal-entries`, `GET /api/strategies`, `GET /api/backtests` (all existing/Task 4).
- Produces: a working `/journal.html` page reachable directly; nothing later in this plan imports from `journal.js` (Task 9 adds to it, not imports it).

- [ ] **Step 1: Create `journal.html`**

Create `frontend/journal.html` (CSS block copied from `history.html` — same design system, only new classes are `.entry-card`/`.entry-title`/`.badge` for the journal-specific list items):

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — Journal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <style>
    :root {
      --bg:#020617;--panel:rgba(15,23,42,.7);--border:rgba(148,163,184,.15);
      --text:#e5e7eb;--muted:#94a3b8;--accent:#22d3ee;
      --green:#22c55e;--red:#ef4444;--warn-bg:rgba(250,204,21,.1);--warn-border:rgba(250,204,21,.3)
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
    body{margin:0;min-height:100vh;color:var(--text);background:var(--bg)}
    .container{max-width:920px;margin:0 auto;padding:48px 24px}
    h1{font-size:32px;margin-bottom:8px}
    .back-link{color:var(--accent);text-decoration:none;font-size:14px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px;margin-top:24px}
    label{display:block;font-size:13px;color:var(--muted);margin:14px 0 6px}
    input,select,textarea{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:15px;font-family:inherit}
    textarea{resize:vertical;min-height:100px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    button{padding:10px 16px;background:var(--accent);color:var(--bg);border:none;
      border-radius:10px;font-weight:700;cursor:pointer;font-size:14px}
    button.secondary{background:transparent;border:1px solid var(--border);color:var(--text)}
    button.danger{background:transparent;border:1px solid var(--red);color:var(--red)}
    button:disabled{opacity:.5;cursor:not-allowed}
    #entriesList{margin-top:20px;display:flex;flex-direction:column;gap:10px}
    .entry-card{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px 16px}
    .entry-card .entry-title{font-weight:700;font-size:16px}
    .entry-card .entry-meta{font-size:13px;color:var(--muted);margin-top:4px}
    .entry-card .entry-body{margin-top:10px;font-size:14px;line-height:1.6;white-space:pre-wrap}
    .badge{display:inline-block;font-size:12px;padding:2px 8px;border-radius:999px;
      background:rgba(34,211,238,.12);color:var(--accent);margin-right:6px}
    .entry-actions{display:flex;gap:8px;margin-top:12px}
    #loadMore{margin-top:16px;width:100%}
    #empty{color:var(--muted);margin-top:20px;display:none}
    #error{color:var(--red);margin-top:14px;display:none}
    #loginNotice{color:var(--muted);margin-top:20px;display:none}
  </style>
</head>
<body>
<div class="container">
  <a class="back-link" href="/">← Back</a>
  <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
  <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
  <a href="/backtest.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Backtest</a>
  <a href="/strategy-builder.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Strategy Builder</a>
  <h1>Journal</h1>
  <p style="color:var(--muted)">Reflections on your backtests and strategies — not a trade log. Dot.Charts never stores real positions or orders.</p>

  <div id="loginNotice">Log in to view and write journal entries.</div>

  <div class="card" id="formCard">
    <h3 id="formTitle" style="margin-top:0">New entry</h3>
    <label for="entryTitle">Title</label>
    <input id="entryTitle" placeholder="What are you reflecting on?" />
    <label for="entryBody">Notes</label>
    <textarea id="entryBody" placeholder="Freeform notes..."></textarea>
    <div class="row">
      <div>
        <label for="entrySymbol">Symbol (optional)</label>
        <input id="entrySymbol" placeholder="e.g. AAPL, BTC/USDT" />
      </div>
      <div></div>
    </div>
    <div class="row">
      <div>
        <label for="entryStrategy">Link to a saved strategy (optional)</label>
        <select id="entryStrategy"><option value="">— none —</option></select>
      </div>
      <div>
        <label for="entryBacktest">Link to a past backtest (optional)</label>
        <select id="entryBacktest"><option value="">— none —</option></select>
      </div>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px">
      <button id="saveButton">Save entry</button>
      <button id="cancelEditButton" class="secondary" style="display:none">Cancel edit</button>
    </div>
    <div id="formError" style="color:var(--red);margin-top:10px;display:none"></div>
  </div>

  <div id="error"></div>
  <div id="empty">No journal entries yet.</div>
  <div id="entriesList"></div>
  <button id="loadMore" class="secondary" style="display:none">Load more</button>
</div>
<script type="module" src="/src/journal.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create `journal.js`**

Create `frontend/src/journal.js`:

```javascript
import { getToken, clearToken, isLoggedIn } from './auth.js';

const API_BASE = 'http://localhost:8000/api';

const authStateEl = document.getElementById('authState');
const loginNoticeEl = document.getElementById('loginNotice');
const formCardEl = document.getElementById('formCard');

if (isLoggedIn()) {
  authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
  document.getElementById('logoutLink').addEventListener('click', (e) => {
    e.preventDefault();
    clearToken();
    window.location.reload();
  });
} else {
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  loginNoticeEl.style.display = 'block';
  formCardEl.style.display = 'none';
}

const errorEl = document.getElementById('error');
const emptyEl = document.getElementById('empty');
const listEl = document.getElementById('entriesList');
const loadMoreButton = document.getElementById('loadMore');

const titleInput = document.getElementById('entryTitle');
const bodyInput = document.getElementById('entryBody');
const symbolInput = document.getElementById('entrySymbol');
const strategySelect = document.getElementById('entryStrategy');
const backtestSelect = document.getElementById('entryBacktest');
const saveButton = document.getElementById('saveButton');
const formTitleEl = document.getElementById('formTitle');
const formErrorEl = document.getElementById('formError');

let nextPageUrl = null;
let editingId = null; // set by Task 7

function authHeaders() {
  const token = getToken();
  return token
    ? { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }
    : { Accept: 'application/json', 'Content-Type': 'application/json' };
}

async function handleUnauthorized() {
  clearToken();
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  loginNoticeEl.style.display = 'block';
  formCardEl.style.display = 'none';
  errorEl.textContent = 'Your session has expired. Log in again.';
  errorEl.style.display = 'block';
}

async function loadDropdownOptions(endpoint, selectEl, labelKey) {
  if (!isLoggedIn()) return;

  let url = `${API_BASE}${endpoint}`;
  const items = [];

  while (url) {
    const response = await fetch(url, { headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    if (!response.ok) return;
    const body = await response.json();
    items.push(...(body.data || []));
    url = body.next_page_url || null;
  }

  items.forEach((item) => {
    const opt = document.createElement('option');
    opt.value = item.id;
    opt.textContent = item[labelKey];
    selectEl.appendChild(opt);
  });
}

function renderEntry(entry) {
  const card = document.createElement('div');
  card.className = 'entry-card';
  card.dataset.id = entry.id;

  const badges = [];
  if (entry.symbol) badges.push(`<span class="badge">${entry.symbol}</span>`);
  if (entry.backtest_run_id) badges.push(`<span class="badge">Backtest #${entry.backtest_run_id}</span>`);
  if (entry.custom_strategy_id) badges.push(`<span class="badge">Strategy #${entry.custom_strategy_id}</span>`);

  card.innerHTML = `
    <div class="entry-title">${entry.title}</div>
    <div class="entry-meta">${badges.join('')}${new Date(entry.created_at).toLocaleString()}</div>
    <div class="entry-body">${entry.body}</div>
    <div class="entry-actions">
      <button class="secondary edit-btn">Edit</button>
      <button class="danger delete-btn">Delete</button>
    </div>
  `;

  card.querySelector('.delete-btn').addEventListener('click', () => deleteEntry(entry.id, card));

  listEl.appendChild(card);
}

async function loadEntries(url, { reset }) {
  if (!isLoggedIn()) return;
  errorEl.style.display = 'none';

  try {
    const response = await fetch(url, { headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.message || 'Failed to load journal entries');
    }

    if (reset) {
      listEl.innerHTML = '';
    }

    body.data.forEach(renderEntry);

    nextPageUrl = body.next_page_url;
    loadMoreButton.style.display = nextPageUrl ? 'block' : 'none';
    emptyEl.style.display = reset && body.data.length === 0 ? 'block' : 'none';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

async function deleteEntry(id, cardEl) {
  // Matches history.js's deleteRun() convention exactly -- a destructive
  // action needs a confirm() gate, not an immediate fire.
  if (!confirm('Delete this journal entry? This cannot be undone.')) return;

  try {
    const response = await fetch(`${API_BASE}/journal-entries/${id}`, { method: 'DELETE', headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    if (!response.ok) throw new Error('Failed to delete entry');
    cardEl.remove();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

saveButton.addEventListener('click', async () => {
  formErrorEl.style.display = 'none';

  const payload = {
    title: titleInput.value,
    body: bodyInput.value,
    symbol: symbolInput.value || null,
    backtest_run_id: backtestSelect.value || null,
    custom_strategy_id: strategySelect.value || null,
  };

  try {
    const response = await fetch(`${API_BASE}/journal-entries`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify(payload),
    });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    const body = await response.json();
    if (!response.ok) {
      throw new Error(body.error || Object.values(body.errors || {}).flat().join(' ') || 'Failed to save entry');
    }

    titleInput.value = '';
    bodyInput.value = '';
    symbolInput.value = '';
    strategySelect.value = '';
    backtestSelect.value = '';

    listEl.innerHTML = '';
    loadEntries(`${API_BASE}/journal-entries`, { reset: true });
  } catch (err) {
    formErrorEl.textContent = err.message;
    formErrorEl.style.display = 'block';
  }
});

loadMoreButton.addEventListener('click', () => {
  if (nextPageUrl) loadEntries(nextPageUrl, { reset: false });
});

loadDropdownOptions('/strategies', strategySelect, 'name');
loadDropdownOptions('/backtests', backtestSelect, 'symbol');
loadEntries(`${API_BASE}/journal-entries`, { reset: true });
```

- [ ] **Step 3: Manual browser verification**

Start both servers (`chartsense-backend` on 8000, `chartsense` frontend on 3000 — see `.claude/launch.json` at the repo root, `~/Dot/.claude/launch.json`). Register or log in a test user. Navigate to `/journal.html`. Confirm:
- Logged out: form is hidden, "Log in to view and write journal entries" shows.
- Logged in: create an entry with just title+body — it appears in the list below with no badges.
- Create a second entry with a symbol and (if you have any saved strategies/backtests) a linked strategy/backtest — confirm the badges render.
- No console errors (`read_console_messages`, `onlyErrors: true`).

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/journal.html frontend/src/journal.js
git commit -m "feat(journal): add journal.html list + create form

Matches history.html/history.js's structure (auth-state header,
paginated list, load-more). Dropdowns for linking a saved
strategy/backtest use the same exhaustive next_page_url-follow
pattern already established in strategy-builder.js.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: `journal.js` — wire the edit flow

**Files:**
- Modify: `frontend/src/journal.js`

**Interfaces:**
- Consumes: `editingId` module-level variable declared in Task 6 (currently unused); `PATCH /api/journal-entries/{id}` (Task 5).

- [ ] **Step 1: Wire the Edit button and PATCH submission**

Modify `frontend/src/journal.js`. In `renderEntry()`, wire the edit button (add this line next to the existing `delete-btn` listener):

```diff
   card.querySelector('.delete-btn').addEventListener('click', () => deleteEntry(entry.id, card));
+  card.querySelector('.edit-btn').addEventListener('click', () => startEdit(entry));
 
   listEl.appendChild(card);
```

Add these two functions above `saveButton.addEventListener(...)`:

```javascript
const cancelEditButton = document.getElementById('cancelEditButton');

function startEdit(entry) {
  editingId = entry.id;
  titleInput.value = entry.title;
  bodyInput.value = entry.body;
  symbolInput.value = entry.symbol || '';
  strategySelect.value = entry.custom_strategy_id || '';
  backtestSelect.value = entry.backtest_run_id || '';
  formTitleEl.textContent = 'Edit entry';
  saveButton.textContent = 'Update entry';
  cancelEditButton.style.display = 'inline-block';
  formCardEl.scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
  editingId = null;
  titleInput.value = '';
  bodyInput.value = '';
  symbolInput.value = '';
  strategySelect.value = '';
  backtestSelect.value = '';
  formTitleEl.textContent = 'New entry';
  saveButton.textContent = 'Save entry';
  cancelEditButton.style.display = 'none';
}

cancelEditButton.addEventListener('click', resetForm);
```

Replace the body of the existing `saveButton.addEventListener('click', async () => { ... })` handler to branch on `editingId`:

```diff
 saveButton.addEventListener('click', async () => {
   formErrorEl.style.display = 'none';
 
   const payload = {
     title: titleInput.value,
     body: bodyInput.value,
     symbol: symbolInput.value || null,
     backtest_run_id: backtestSelect.value || null,
     custom_strategy_id: strategySelect.value || null,
   };
 
   try {
-    const response = await fetch(`${API_BASE}/journal-entries`, {
-      method: 'POST',
+    const url = editingId ? `${API_BASE}/journal-entries/${editingId}` : `${API_BASE}/journal-entries`;
+    const method = editingId ? 'PATCH' : 'POST';
+    const response = await fetch(url, {
+      method,
       headers: authHeaders(),
       body: JSON.stringify(payload),
     });
     if (response.status === 401) {
       await handleUnauthorized();
       return;
     }
     const body = await response.json();
     if (!response.ok) {
       throw new Error(body.error || Object.values(body.errors || {}).flat().join(' ') || 'Failed to save entry');
     }
 
-    titleInput.value = '';
-    bodyInput.value = '';
-    symbolInput.value = '';
-    strategySelect.value = '';
-    backtestSelect.value = '';
-
+    resetForm();
     listEl.innerHTML = '';
     loadEntries(`${API_BASE}/journal-entries`, { reset: true });
   } catch (err) {
     formErrorEl.textContent = err.message;
     formErrorEl.style.display = 'block';
   }
 });
```

- [ ] **Step 2: Manual browser verification**

Reload `/journal.html`. Click "Edit" on an existing entry — confirm the form populates with its data, the button says "Update entry", and "Cancel edit" appears. Change the title, click "Update entry" — confirm the list re-renders with the new title and the form resets to "New entry" mode. Click "Cancel edit" mid-edit on another entry — confirm the form clears without saving anything.

- [ ] **Step 3: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/journal.js
git commit -m "feat(journal): wire edit flow in journal.js

Save button branches POST/PATCH on whether an edit is in progress;
Cancel edit resets the form without submitting.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: Nav link rollout across existing pages

**Files:**
- Modify: `frontend/index.html`
- Modify: `frontend/backtest.html`
- Modify: `frontend/strategy-builder.html`
- Modify: `frontend/history.html`
- Modify: `frontend/journal.html`

**Interfaces:** None — purely navigational HTML, no JS/API surface.

- [ ] **Step 1: Add the Journal link to `index.html`**

Modify `frontend/index.html`, inside the `<nav>` block:

```diff
   <nav style="margin-top:14px">
     <a href="/backtest.html" style="color:var(--accent);text-decoration:none;font-size:15px">
       → Run a real backtest
     </a>
     <a href="/strategy-builder.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
       Strategy Builder
     </a>
+    <a href="/journal.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
+      Journal
+    </a>
     <a href="/login.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
       Log in
     </a>
   </nav>
```

- [ ] **Step 2: Add the Journal link to `backtest.html`**

Modify `frontend/backtest.html`, at the existing nav-link row (near the top, alongside the History/Strategy Builder links):

```diff
   <a class="back-link" href="/">← Back</a>
   <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
+  <a href="/journal.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Journal</a>
   <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
   <a href="/strategy-builder.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Strategy Builder</a>
```

- [ ] **Step 3: Add the Journal link to `strategy-builder.html`**

Modify `frontend/strategy-builder.html`:

```diff
   <a class="back-link" href="/">← Back</a>
   <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
+  <a href="/journal.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Journal</a>
   <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
   <a href="/backtest.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Backtest</a>
```

- [ ] **Step 4: Add the Journal link to `history.html`**

Modify `frontend/history.html`:

```diff
   <a class="back-link" href="/">← Back</a>
+  <a href="/journal.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Journal</a>
   <a href="/backtest.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Run a backtest</a>
   <a href="/strategy-builder.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Strategy Builder</a>
```

- [ ] **Step 5: Add the History/Backtest/Strategy Builder links already present on `journal.html`**

Already done in Task 6's `journal.html` (it links to History, Backtest, Strategy Builder). No change needed here — this step exists only to confirm it during verification.

- [ ] **Step 6: Manual browser verification**

Load each of `index.html`, `backtest.html`, `strategy-builder.html`, `history.html`, `journal.html` and confirm a "Journal" link is visible and navigates correctly (and that `journal.html` itself links back to the other three).

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/index.html frontend/backtest.html frontend/strategy-builder.html frontend/history.html
git commit -m "feat(journal): roll out Journal nav link across existing pages

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 9: History quick-link with prefill

**Files:**
- Modify: `frontend/src/history.js`
- Modify: `frontend/src/journal.js`

**Interfaces:**
- Consumes: `renderRunRow()` in `history.js` (existing); `URLSearchParams` from the page's own `location.search`.

- [ ] **Step 1: Add the "+ Journal" button to `history.js`'s row template**

Modify `frontend/src/history.js`. In `renderRunRow()`, add the button and its handler:

```diff
     <div class="run-actions">
+      <button class="secondary journal-btn">+ Journal</button>
       <button class="secondary rerun-btn">Re-run</button>
       <button class="danger delete-btn">Delete</button>
     </div>
   `;

   row.addEventListener('click', (e) => {
     if (e.target.closest('.run-actions')) return;
     showDetail(run.id);
   });

+  row.querySelector('.journal-btn').addEventListener('click', (e) => {
+    e.stopPropagation();
+    const params = new URLSearchParams({ backtest_run_id: run.id, symbol: run.symbol });
+    window.location.href = `/journal.html?${params.toString()}`;
+  });
+
   row.querySelector('.rerun-btn').addEventListener('click', (e) => {
```

- [ ] **Step 2: Read the prefill query params in `journal.js`**

Modify `frontend/src/journal.js`. Add this near the end of the file, after `loadDropdownOptions(...)` calls but the dropdown for backtests needs to be populated first — so place it after both `loadDropdownOptions` calls and before the final `loadEntries(...)` call:

```diff
 loadDropdownOptions('/strategies', strategySelect, 'name');
-loadDropdownOptions('/backtests', backtestSelect, 'symbol');
+loadDropdownOptions('/backtests', backtestSelect, 'symbol').then(() => {
+  const params = new URLSearchParams(window.location.search);
+  const prefilledBacktestId = params.get('backtest_run_id');
+  const prefilledSymbol = params.get('symbol');
+
+  if (prefilledBacktestId && backtestSelect.querySelector(`option[value="${prefilledBacktestId}"]`)) {
+    backtestSelect.value = prefilledBacktestId;
+  }
+  if (prefilledSymbol) {
+    symbolInput.value = prefilledSymbol;
+  }
+});
 loadEntries(`${API_BASE}/journal-entries`, { reset: true });
```

Note: `loadDropdownOptions` must be declared `async function` (it already is, from Task 6) for `.then()` to work here — no change needed to its own definition, just confirm it's still `async function loadDropdownOptions(...)`.

- [ ] **Step 3: Manual browser verification**

On `history.html`, click "+ Journal" on any backtest row. Confirm you land on `journal.html` with the Symbol field prefilled and the "Link to a past backtest" dropdown pre-selected to that run (this requires the dropdown to have actually loaded that backtest as an option — confirm it's within the user's own recent 20 backtests, or that pagination in `loadDropdownOptions` picked it up if further back).

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/history.js frontend/src/journal.js
git commit -m "feat(journal): add History quick-link with backtest/symbol prefill

+ Journal button on each history.html row opens journal.html with
backtest_run_id and symbol prefilled via query params.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 10: Final verification, wiki.md update, and push

**Files:**
- Modify: `wiki.md`

**Interfaces:** None — documentation and verification only.

- [ ] **Step 1: Full backend verification**

```bash
cd backend
eval "$(/opt/homebrew/bin/brew shellenv)"
php artisan test
./vendor/bin/pint --test
```
Expected: all tests pass (198 backend tests from Tasks 1–5, unless the running count drifted — read the actual total, don't assume); Pint clean on every file this plan touched.

- [ ] **Step 2: Full frontend walkthrough in the browser**

Start both servers, then walk the complete flow fresh: register/log in a test user → run one real backtest (or reuse an existing one) → go to History → click "+ Journal" on that run → confirm prefill → save the entry → confirm it appears in the list with the backtest badge → edit it → delete it → confirm empty state reappears if it was the only entry. Check `read_console_messages` (`onlyErrors: true`) throughout — zero errors expected.

- [ ] **Step 3: Update `wiki.md`**

Modify `wiki.md`:
- §2 Architecture tree: add `JournalEntryController.php` to the controllers list and `JournalEntry` to the `app/Models/` line; add `journal.html`, `journal.js` to the frontend page/src lists.
- §3 Domain Entities: change the "Trading journal entry" row from `**planned**` to `**built**`, with a note describing the schema (title/body/optional symbol/optional links to `BacktestRun`/`CustomStrategy`) and pointing at the schema-invariant test.
- §8 Roadmap: change `- [ ] Build the trading journal...` to `- [x] ~~Build the trading journal...~~ — built`, briefly summarizing what shipped and linking to the design spec.
- Bump the `version` in the frontmatter to the next patch (check the current value in the file first — do not assume what Task 9's git history left it at) and add a Change Log row summarizing this feature, the schema-invariant enforcement, and the real test count.

Use the exact current file content (read it, don't guess the version number or table row text) before editing — this file has been through several increments this session and its exact current state must be read fresh.

- [ ] **Step 4: Commit and push**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add wiki.md
git commit -m "docs: mark trading journal built in wiki.md

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git push origin main
```
