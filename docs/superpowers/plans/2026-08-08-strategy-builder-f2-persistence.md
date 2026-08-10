# Strategy Builder F2: Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let logged-in users save, list, view, and delete custom strategies (F1's rule JSON), with the rule structure validated at save time via a new lightweight analytics endpoint.

**Architecture:** `analytics/main.py` gains `POST /validate-rule`, reusing F1's `evaluate_rule` against a synthetic in-memory DataFrame (no live data fetch). Laravel gains a `custom_strategies` table + `CustomStrategy` model + `CustomStrategyController` (store/index/show/destroy, all behind `auth:sanctum`), calling the new analytics endpoint via a new `AnalyticsServiceClient::validateRule` method before persisting.

**Tech Stack:** Python (`pandas`, existing `evaluate_rule`), PHP/Laravel (Eloquent, Sanctum — both already in use).

## Global Constraints

- Every `custom_strategies` endpoint requires `auth:sanctum` — no anonymous case, unlike `BacktestRun`'s `store` (per spec's Auth section).
- `/validate-rule` returns HTTP 200 with `{"valid": false, "error": "..."}` for an invalid rule — never 422 — since answering "is this valid" is the endpoint's whole successful job (per spec's Save-Time Validation section).
- No update/edit endpoint in this slice — create/list/show/delete only (per spec's Endpoint Contracts section).
- Always resolve the authenticated user via `$request->user('sanctum')`, never bare `$request->user()` (per the codebase's established Sanctum-guard convention, confirmed against `BacktestController`).

---

### Task 1: `POST /validate-rule` analytics endpoint

**Files:**
- Modify: `analytics/main.py`
- Modify: `analytics/schemas.py`
- Test: `analytics/tests/test_validate_rule_endpoint.py`

**Interfaces:**
- Consumes: `evaluate_rule(df, rule)` and `InvalidStrategyParamsError` from `analytics/strategies/custom_rules.py` (F1, already built).
- Produces: `POST /validate-rule` accepting `{"rules": {"entry": {...}, "exit": {...}}}`, returning `{"valid": true}` or `{"valid": false, "error": str}` — Task 3's `AnalyticsServiceClient::validateRule` relies on this exact route path and response shape.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_validate_rule_endpoint.py
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _valid_rules():
    return {
        "entry": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
            ],
        },
        "exit": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_below", "right": {"indicator": "ema", "length": 20}},
            ],
        },
    }


def test_validate_rule_returns_valid_true_for_a_well_formed_rule():
    response = client.post("/validate-rule", json={"rules": _valid_rules()})

    assert response.status_code == 200
    assert response.json() == {"valid": True}


def test_validate_rule_returns_valid_false_for_an_unknown_comparator():
    rules = _valid_rules()
    rules["entry"]["conditions"][0]["comparator"] = "not_a_real_comparator"

    response = client.post("/validate-rule", json={"rules": rules})

    assert response.status_code == 200
    body = response.json()
    assert body["valid"] is False
    assert "not_a_real_comparator" in body["error"]


def test_validate_rule_returns_valid_false_for_an_unknown_indicator():
    rules = _valid_rules()
    rules["exit"]["conditions"][0]["left"] = {"indicator": "made_up"}

    response = client.post("/validate-rule", json={"rules": rules})

    assert response.status_code == 200
    assert response.json()["valid"] is False


def test_validate_rule_requires_both_entry_and_exit():
    response = client.post("/validate-rule", json={"rules": {"entry": _valid_rules()["entry"]}})

    assert response.status_code == 200
    body = response.json()
    assert body["valid"] is False
    assert "exit" in body["error"]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_validate_rule_endpoint.py -v`
Expected: FAIL with 404 (no `/validate-rule` route exists yet)

- [ ] **Step 3: Write the implementation**

In `analytics/schemas.py`, append:

```python
class ValidateRuleRequest(BaseModel):
    rules: dict
```

In `analytics/main.py`, add the import:

```python
from strategies.custom_rules import evaluate_rule, InvalidStrategyParamsError
```

alongside the existing `from strategies.custom_rules import InvalidStrategyParamsError` line — replace that existing line with the combined import above (it already imports `InvalidStrategyParamsError`; this just adds `evaluate_rule` to the same import statement).

Then append this route at the end of `main.py`:

```python
@app.post("/validate-rule")
def validate_rule(request: ValidateRuleRequest):
    # A small synthetic DataFrame -- no live market-data fetch needed.
    # 250 bars is enough for any indicator length used in practice
    # (the longest built-in default, EMA/SMA/RSI/ATR/Bollinger, all stay
    # well under 250) to resolve without an insufficient-history error.
    idx = pd.date_range("2020-01-01", periods=250, freq="D")
    close = pd.Series([100.0 + (i % 20) * 0.5 for i in range(250)], index=idx)
    synthetic_df = pd.DataFrame({
        "open": close, "high": close + 1, "low": close - 1, "close": close, "volume": 1000,
    })

    entry_rule = request.rules.get("entry")
    exit_rule = request.rules.get("exit")

    if not entry_rule:
        return {"valid": False, "error": "rules must include an 'entry' rule"}
    if not exit_rule:
        return {"valid": False, "error": "rules must include an 'exit' rule"}

    try:
        evaluate_rule(synthetic_df, entry_rule)
        evaluate_rule(synthetic_df, exit_rule)
    except InvalidStrategyParamsError as exc:
        return {"valid": False, "error": str(exc)}

    return {"valid": True}
```

Also add `import pandas as pd` to `main.py`'s imports if not already present (check the top of the file first — it currently has no direct `pandas` import since `fetch_ohlcv_cached`/strategy modules handle DataFrames internally).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_validate_rule_endpoint.py -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/main.py analytics/schemas.py analytics/tests/test_validate_rule_endpoint.py
git commit -m "feat(strategy-builder): add POST /validate-rule endpoint reusing evaluate_rule"
```

---

### Task 2: `custom_strategies` migration + `CustomStrategy` model

**Files:**
- Create: `backend/database/migrations/2026_08_08_000001_create_custom_strategies_table.php`
- Create: `backend/app/Models/CustomStrategy.php`
- Create: `backend/database/factories/CustomStrategyFactory.php`

**Interfaces:**
- Consumes: `App\Models\User` (existing).
- Produces: `custom_strategies` table (`user_id`, `name`, `description`, `rules`, timestamps); `CustomStrategy` model with `fillable = ['user_id', 'name', 'description', 'rules']`, `casts = ['rules' => 'array']`, `belongsTo(User::class)` — Task 4's controller relies on this exact model shape.

- [ ] **Step 1: Write the migration**

```php
<?php
// backend/database/migrations/2026_08_08_000001_create_custom_strategies_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_strategies', function (Blueprint $table) {
            $table->id();
            // NOT nullable, unlike backtest_runs.user_id -- a saved,
            // named strategy has no meaning without an owner to retrieve
            // it later (every custom_strategies endpoint requires login,
            // no anonymous case).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules'); // {"entry": {...}, "exit": {...}} -- F1's schema
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_strategies');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `cd backend && php artisan migrate`
Expected: `custom_strategies` table created with no errors. (Test runs use `RefreshDatabase`, which re-runs migrations automatically, but running it locally now confirms the migration itself is valid.)

- [ ] **Step 3: Write the model**

```php
<?php
// backend/app/Models/CustomStrategy.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomStrategy extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php
// backend/database/factories/CustomStrategyFactory.php

namespace Database\Factories;

use App\Models\CustomStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomStrategyFactory extends Factory
{
    protected $model = CustomStrategy::class;

    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => 'EMA Crossover',
            'description' => null,
            'rules' => [
                'entry' => [
                    'combinator' => 'all',
                    'conditions' => [
                        ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_above', 'right' => ['indicator' => 'ema', 'length' => 20]],
                    ],
                ],
                'exit' => [
                    'combinator' => 'all',
                    'conditions' => [
                        ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_below', 'right' => ['indicator' => 'ema', 'length' => 20]],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_08_08_000001_create_custom_strategies_table.php backend/app/Models/CustomStrategy.php backend/database/factories/CustomStrategyFactory.php
git commit -m "feat(strategy-builder): add custom_strategies table and CustomStrategy model"
```

---

### Task 3: `AnalyticsServiceClient::validateRule`

**Files:**
- Modify: `backend/app/Services/AnalyticsServiceClient.php`
- Modify: `backend/tests/Unit/AnalyticsServiceClientTest.php` (already exists — append the new test methods inside the existing test class, don't overwrite the file)

**Interfaces:**
- Consumes: `POST /validate-rule` from Task 1, exact request/response shape.
- Produces: `AnalyticsServiceClient::validateRule(array $rules): array` returning the decoded `{"valid": bool, "error"?: string}` shape — Task 4's controller relies on this exact method name and return shape.

- [ ] **Step 1: Write the failing test**

Append these two test methods inside the existing `AnalyticsServiceClientTest` class in `backend/tests/Unit/AnalyticsServiceClientTest.php` (the file already imports `AnalyticsServiceClient`, `Http`, and extends `TestCase` — no new imports needed):

```php
    public function test_validate_rule_returns_the_decoded_response(): void
    {
        Http::fake([
            '*/validate-rule' => Http::response(['valid' => true], 200),
        ]);

        $client = new AnalyticsServiceClient('http://localhost:8001');
        $result = $client->validateRule(['entry' => ['combinator' => 'all', 'conditions' => []]]);

        $this->assertSame(['valid' => true], $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/validate-rule'));
    }

    public function test_validate_rule_returns_the_invalid_response_with_error(): void
    {
        Http::fake([
            '*/validate-rule' => Http::response(['valid' => false, 'error' => 'Unknown comparator: bogus'], 200),
        ]);

        $client = new AnalyticsServiceClient('http://localhost:8001');
        $result = $client->validateRule(['entry' => ['combinator' => 'all', 'conditions' => []]]);

        $this->assertSame(['valid' => false, 'error' => 'Unknown comparator: bogus'], $result);
    }
```

(Leave the existing class's own closing `}` where it already is — these two methods go inside it, not after it.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AnalyticsServiceClientTest`
Expected: FAIL — `Call to undefined method App\Services\AnalyticsServiceClient::validateRule()`

- [ ] **Step 3: Write the implementation**

In `backend/app/Services/AnalyticsServiceClient.php`, append this method inside the class, after `analyzeChart`:

```php
    /**
     * @param array $rules matches the Python service's {"entry": {...}, "exit": {...}} rule shape
     * @return array {"valid": bool, "error"?: string} -- always 200 from the analytics service,
     *   since "the rule is invalid" is itself a successfully-answered question, not a service error
     * @throws RuntimeException on a non-2xx response or connection failure (an actual infrastructure problem)
     */
    public function validateRule(array $rules): array
    {
        $response = Http::timeout(15)->post("{$this->baseUrl}/validate-rule", ['rules' => $rules]);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=AnalyticsServiceClientTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AnalyticsServiceClient.php backend/tests/Unit/AnalyticsServiceClientTest.php
git commit -m "feat(strategy-builder): add AnalyticsServiceClient::validateRule"
```

---

### Task 4: `CustomStrategyController` (store/index/show/destroy) + routes

**Files:**
- Create: `backend/app/Http/Controllers/CustomStrategyController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/CustomStrategyControllerTest.php`

**Interfaces:**
- Consumes: `CustomStrategy` model from Task 2; `AnalyticsServiceClient::validateRule` from Task 3.
- Produces: `POST /api/strategies`, `GET /api/strategies`, `GET /api/strategies/{id}`, `DELETE /api/strategies/{id}` — final task in the plan, nothing downstream relies on this.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/CustomStrategyControllerTest.php

namespace Tests\Feature;

use App\Models\CustomStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomStrategyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validRules(): array
    {
        return [
            'entry' => [
                'combinator' => 'all',
                'conditions' => [
                    ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_above', 'right' => ['indicator' => 'ema', 'length' => 20]],
                ],
            ],
            'exit' => [
                'combinator' => 'all',
                'conditions' => [
                    ['left' => ['indicator' => 'ema', 'length' => 5], 'comparator' => 'crosses_below', 'right' => ['indicator' => 'ema', 'length' => 20]],
                ],
            ],
        ];
    }

    public function test_store_persists_a_valid_strategy_for_the_authenticated_user(): void
    {
        Http::fake(['*/validate-rule' => Http::response(['valid' => true], 200)]);
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/strategies', [
            'name' => 'EMA Crossover',
            'rules' => $this->validRules(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'EMA Crossover');
        $this->assertDatabaseHas('custom_strategies', ['name' => 'EMA Crossover', 'user_id' => $user->id]);
    }

    public function test_store_returns_422_when_analytics_marks_the_rule_invalid(): void
    {
        Http::fake(['*/validate-rule' => Http::response(['valid' => false, 'error' => 'Unknown comparator: bogus'], 200)]);
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/strategies', [
            'name' => 'Bad Strategy',
            'rules' => $this->validRules(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('custom_strategies', ['name' => 'Bad Strategy']);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/strategies', ['name' => 'X', 'rules' => $this->validRules()]);

        $response->assertStatus(401);
    }

    public function test_index_returns_only_the_authenticated_users_strategies(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        CustomStrategy::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
        CustomStrategy::factory()->create(['user_id' => $otherUser->id, 'name' => 'Not Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/strategies');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Not Mine'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/strategies');

        $response->assertStatus(401);
    }

    public function test_show_returns_an_owned_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/strategies/{$strategy->id}");

        $response->assertOk();
        $response->assertJsonPath('name', 'Mine');
    }

    public function test_show_returns_404_for_another_users_strategy(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(404);
    }

    public function test_destroy_removes_an_owned_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('custom_strategies', ['id' => $strategy->id]);
    }

    public function test_destroy_returns_404_for_another_users_strategy_and_does_not_delete_it(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('custom_strategies', ['id' => $strategy->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $strategy = CustomStrategy::factory()->create();

        $response = $this->deleteJson("/api/strategies/{$strategy->id}");

        $response->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=CustomStrategyControllerTest`
Expected: FAIL — every test 404s or errors, since `CustomStrategyController` and the `/api/strategies` routes don't exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php
// backend/app/Http/Controllers/CustomStrategyController.php

namespace App\Http\Controllers;

use App\Models\CustomStrategy;
use App\Services\AnalyticsServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomStrategyController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'rules' => 'required|array',
        ]);

        $validation = $this->analyticsClient->validateRule($validated['rules']);

        if (($validation['valid'] ?? false) !== true) {
            return response()->json([
                'error' => $validation['error'] ?? 'Invalid strategy rules',
            ], 422);
        }

        $strategy = CustomStrategy::create([
            'user_id' => $request->user('sanctum')->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'rules' => $validated['rules'],
        ]);

        return response()->json($strategy, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $strategies = CustomStrategy::where('user_id', $request->user('sanctum')->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        return response()->json($strategies);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $strategy = CustomStrategy::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        return response()->json($strategy);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $strategy = CustomStrategy::where('id', $id)
            ->where('user_id', $request->user('sanctum')->id)
            ->firstOrFail();

        $strategy->delete();

        return response()->json(['success' => true]);
    }
}
```

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\CustomStrategyController;
```

alongside the existing controller imports, then add these routes inside the existing `Route::middleware('auth:sanctum')->group(function () { ... })` block (the same block containing `/backtests` index/show/destroy):

```php
    Route::post('/strategies', [CustomStrategyController::class, 'store']);
    Route::get('/strategies', [CustomStrategyController::class, 'index']);
    Route::get('/strategies/{id}', [CustomStrategyController::class, 'show']);
    Route::delete('/strategies/{id}', [CustomStrategyController::class, 'destroy']);
```

Note `store` is inside the `auth:sanctum` group here — unlike `BacktestController::store`, which sits outside it, per this slice's "login required for all operations" decision.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=CustomStrategyControllerTest`
Expected: PASS (10 tests)

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Manual verification**

1. Start the analytics service: `cd analytics && .venv/bin/uvicorn main:app --port 8001`.
2. Start the backend: use the `chartsense-backend` launch config (port 8000).
3. Register/login a test user via `curl` against `/api/register`, capturing the bearer token.
4. `POST /api/strategies` with a real rule payload and the bearer token; confirm 201 and the row appears via `GET /api/strategies`.
5. `POST /api/strategies` with an invalid rule (unknown comparator); confirm 422.
6. `GET /api/strategies/{id}` and `DELETE /api/strategies/{id}` with a second user's token against the first user's strategy; confirm both 404.
7. Stop both services.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/CustomStrategyController.php backend/routes/api.php backend/tests/Feature/CustomStrategyControllerTest.php
git commit -m "feat(strategy-builder): add CustomStrategyController (store/index/show/destroy)"
```
