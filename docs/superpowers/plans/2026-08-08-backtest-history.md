# Backtest History UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a logged-in user browse, filter, inspect, delete, and re-run their own past backtests via a new `history.html` page backed by three new endpoints (`GET /api/backtests`, `GET /api/backtests/{id}`, `DELETE /api/backtests/{id}`), all scoped to the authenticated owner.

**Architecture:** `BacktestController` gains `index`/`show`/`destroy` alongside its existing `store`, all behind `auth:sanctum` (a real change from the anonymous-friendly `POST /api/backtests`, since these operate on existing personal data). The frontend's result-rendering logic (`renderResult`/`renderEquityCurve`, currently private to `backtest.js`) is extracted into a shared `results-renderer.js` module so `history.html`'s detail view reuses it instead of duplicating it. "Re-run" pre-fills the backtest form via `sessionStorage` rather than auto-submitting, so it never silently spends a rate-limit slot.

**Tech Stack:** Same as prior slices — Laravel 12/PHP 8.2, vanilla JS/Vite frontend, no new dependencies.

## Global Constraints

- All three new endpoints require `auth:sanctum` — no anonymous history, no exceptions.
- Ownership checks in `show`/`destroy` return `404` (via `firstOrFail()`), never `403` — a guessed ID must not confirm its own existence.
- "Re-run" pre-fills the form only; it must never auto-submit a new backtest.
- Delete requires an explicit confirm step in the UI before the `DELETE` request fires.
- Pagination is Laravel's built-in `paginate()` plus a "Load more" button — no numbered pages, no infinite scroll, no new pagination library.
- No live-network calls in tests; no JS test framework (matches existing project convention) — frontend verification is manual, in the browser.

---

## File Structure

```
ChartSense/
├── backend/
│   ├── app/Http/Controllers/BacktestController.php   # MODIFY — add index/show/destroy
│   ├── routes/api.php                                 # MODIFY — add 3 auth:sanctum routes
│   └── tests/Feature/BacktestControllerTest.php        # MODIFY — add index/show/destroy tests
└── frontend/
    ├── src/results-renderer.js                         # NEW — extracted from backtest.js
    ├── src/backtest.js                                 # MODIFY — use shared renderer, read re-run prefill
    ├── backtest.html                                   # MODIFY — "History" nav link
    ├── history.html                                    # NEW
    ├── src/history.js                                  # NEW
    └── vite.config.js                                  # MODIFY — add history.html entry
```

---

### Task 1: `GET /api/backtests` — list with filters + pagination

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\BacktestRun` (existing), `$request->user('sanctum')` (established pattern from the auth slice).
- Produces: `GET /api/backtests` — Laravel's standard paginator JSON shape (`data`, `next_page_url`, `total`, etc.), filterable by `strategy`, `asset_class`, `status` query params, scoped to the authenticated user. Consumed by Task 5 (`history.js`).

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/BacktestControllerTest.php — add these to the class

public function test_index_returns_only_the_authenticated_users_runs(): void
{
    $user = \App\Models\User::factory()->create();
    $otherUser = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    \App\Models\BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL']);
    \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id, 'symbol' => 'MSFT']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/backtests');

    $response->assertOk();
    $symbols = collect($response->json('data'))->pluck('symbol');
    $this->assertTrue($symbols->contains('AAPL'));
    $this->assertFalse($symbols->contains('MSFT'));
}

public function test_index_filters_by_strategy_asset_class_and_status(): void
{
    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    \App\Models\BacktestRun::factory()->create([
        'user_id' => $user->id, 'strategy' => 'ma_crossover', 'asset_class' => 'equity', 'status' => 'complete',
    ]);
    \App\Models\BacktestRun::factory()->create([
        'user_id' => $user->id, 'strategy' => 'method_714', 'asset_class' => 'crypto', 'status' => 'failed',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/backtests?strategy=ma_crossover&asset_class=equity&status=complete');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame('ma_crossover', $response->json('data.0.strategy'));
}

public function test_index_requires_authentication(): void
{
    $response = $this->getJson('/api/backtests');

    $response->assertStatus(401);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=test_index`
Expected: FAIL — route `GET /api/backtests` doesn't exist (`404`); also, `BacktestRun` has no factory yet (needed for these tests)

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/database/factories/BacktestRunFactory.php — new file

namespace Database\Factories;

use App\Models\BacktestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'status' => 'complete',
            'results' => null,
            'error' => null,
        ];
    }
}
```

```php
// backend/app/Http/Controllers/BacktestController.php — add this method to the class

public function index(Request $request): JsonResponse
{
    $query = BacktestRun::where('user_id', $request->user('sanctum')->id)
        ->orderByDesc('created_at');

    if ($request->filled('strategy')) {
        $query->where('strategy', $request->string('strategy'));
    }
    if ($request->filled('asset_class')) {
        $query->where('asset_class', $request->string('asset_class'));
    }
    if ($request->filled('status')) {
        $query->where('status', $request->string('status'));
    }

    $runs = $query->paginate(20)->appends($request->query());

    return response()->json($runs);
}
```

```php
// backend/routes/api.php — final version

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/backtests', [BacktestController::class, 'index']);
});

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart'])
    ->middleware('throttle:chart-analysis');
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware('throttle:backtests');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_index`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/database/factories/BacktestRunFactory.php backend/app/Http/Controllers/BacktestController.php \
        backend/routes/api.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): add GET /api/backtests (paginated, filtered, owner-scoped)"
```

---

### Task 2: `GET /api/backtests/{id}` — detail

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\BacktestRun` (Task 1's factory), same ownership pattern as `index`.
- Produces: `GET /api/backtests/{id}` — full `BacktestRun` row as JSON, `404` if not owned/not found. Consumed by Task 5 (`history.js`'s detail view).

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/BacktestControllerTest.php — add these to the class

public function test_show_returns_full_detail_for_an_owned_run(): void
{
    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;
    $run = \App\Models\BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/backtests/{$run->id}");

    $response->assertOk();
    $response->assertJsonPath('symbol', 'AAPL');
}

public function test_show_returns_404_for_another_users_run(): void
{
    $user = \App\Models\User::factory()->create();
    $otherUser = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;
    $run = \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/backtests/{$run->id}");

    $response->assertStatus(404);
}

public function test_show_returns_404_for_a_nonexistent_run(): void
{
    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/backtests/999999');

    $response->assertStatus(404);
}

public function test_show_requires_authentication(): void
{
    $run = \App\Models\BacktestRun::factory()->create();

    $response = $this->getJson("/api/backtests/{$run->id}");

    $response->assertStatus(401);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_show`
Expected: FAIL — route `GET /api/backtests/{id}` doesn't exist

- [ ] **Step 3: Write minimal implementation**

```php
// backend/app/Http/Controllers/BacktestController.php — add this method

public function show(Request $request, int $id): JsonResponse
{
    $run = BacktestRun::where('id', $id)
        ->where('user_id', $request->user('sanctum')->id)
        ->firstOrFail();

    return response()->json($run);
}
```

```php
// backend/routes/api.php — add inside the auth:sanctum group, after 'backtests'
    Route::get('/backtests/{id}', [BacktestController::class, 'show']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_show`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/BacktestController.php backend/routes/api.php \
        backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): add GET /api/backtests/{id} (owner-scoped detail)"
```

---

### Task 3: `DELETE /api/backtests/{id}`

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: same ownership pattern as `show`.
- Produces: `DELETE /api/backtests/{id}`. Consumed by Task 6 (`history.js`'s delete button).

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/BacktestControllerTest.php — add these to the class

public function test_destroy_removes_an_owned_run(): void
{
    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;
    $run = \App\Models\BacktestRun::factory()->create(['user_id' => $user->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/backtests/{$run->id}");

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $this->assertDatabaseMissing('backtest_runs', ['id' => $run->id]);
}

public function test_destroy_returns_404_for_another_users_run_and_does_not_delete_it(): void
{
    $user = \App\Models\User::factory()->create();
    $otherUser = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;
    $run = \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/backtests/{$run->id}");

    $response->assertStatus(404);
    $this->assertDatabaseHas('backtest_runs', ['id' => $run->id]);
}

public function test_destroy_requires_authentication(): void
{
    $run = \App\Models\BacktestRun::factory()->create();

    $response = $this->deleteJson("/api/backtests/{$run->id}");

    $response->assertStatus(401);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_destroy`
Expected: FAIL — route `DELETE /api/backtests/{id}` doesn't exist

- [ ] **Step 3: Write minimal implementation**

```php
// backend/app/Http/Controllers/BacktestController.php — add this method

public function destroy(Request $request, int $id): JsonResponse
{
    $run = BacktestRun::where('id', $id)
        ->where('user_id', $request->user('sanctum')->id)
        ->firstOrFail();

    $run->delete();

    return response()->json(['success' => true]);
}
```

```php
// backend/routes/api.php — add inside the auth:sanctum group, after the show route
    Route::delete('/backtests/{id}', [BacktestController::class, 'destroy']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_destroy`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full Laravel test suite**

Run: `php artisan test`
Expected: all tests pass (existing 41 + 10 new from Tasks 1–3 = 51)

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/BacktestController.php backend/routes/api.php \
        backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): add DELETE /api/backtests/{id} (owner-scoped)"
```

---

### Task 4: Extract shared `results-renderer.js`

**Files:**
- Create: `frontend/src/results-renderer.js`
- Modify: `frontend/src/backtest.js`

**Interfaces:**
- Consumes: nothing new — this is a pure extraction, no behavior change.
- Produces: `results-renderer.js` exports `renderBacktestResult(result)` — queries the same fixed DOM IDs (`resultTitle`, `mTotalReturn`, `mWinRate`, `mDrawdown`, `mSharpe`, `mTrades`, `mLosingTrades`, `equityCurve`, `dConfidence`, `dAttribution`, `dRisk`, and the `results` container) that `backtest.html` already defines. Used by Task 5 (`history.js`) and Task 6.

- [ ] **Step 1: Create the shared module**

```js
// frontend/src/results-renderer.js
export function renderBacktestResult(result) {
  document.getElementById('resultTitle').textContent = `${result.symbol} — ${result.strategy}`;

  const m = result.metrics;
  document.getElementById('mTotalReturn').textContent = `${m.total_return_pct.toFixed(2)}%`;
  document.getElementById('mWinRate').textContent = `${m.win_rate_pct.toFixed(1)}%`;
  document.getElementById('mDrawdown').textContent = `${m.max_drawdown_pct.toFixed(2)}%`;
  document.getElementById('mSharpe').textContent = m.sharpe_ratio == null ? '—' : m.sharpe_ratio.toFixed(2);
  document.getElementById('mTrades').textContent = m.trade_count;
  document.getElementById('mLosingTrades').textContent = m.losing_trade_count;

  renderEquityCurve(result.equity_curve);

  const d = result.disclosure;
  document.getElementById('dConfidence').textContent = `Confidence: ${d.confidence_band}`;
  document.getElementById('dAttribution').textContent = d.attribution;
  document.getElementById('dRisk').textContent = d.risk_disclosure;

  document.getElementById('results').style.display = 'block';
}

function renderEquityCurve(points) {
  const svg = document.getElementById('equityCurve');
  svg.innerHTML = '';
  if (!points || points.length < 2) return;

  const width = svg.clientWidth || 860;
  const height = 160;
  const values = points.map((p) => p.equity);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const coords = points.map((p, i) => {
    const x = (i / (points.length - 1)) * width;
    const y = height - ((p.equity - min) / range) * height;
    return `${x},${y}`;
  });

  const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  polyline.setAttribute('points', coords.join(' '));
  polyline.setAttribute('fill', 'none');
  polyline.setAttribute('stroke', '#22d3ee');
  polyline.setAttribute('stroke-width', '2');
  svg.appendChild(polyline);
}
```

- [ ] **Step 2: Update `backtest.js` to use it**

```js
// frontend/src/backtest.js — replace the top import line and remove the two now-duplicate
// function definitions (renderResult, renderEquityCurve) from the bottom of the file

import { getToken, clearToken, isLoggedIn } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';

// ... (unchanged: API_BASE, element consts, authState block, assetClassSelect listener,
//      currentSymbol(), runButton click handler up to the try/catch)
```

```js
// frontend/src/backtest.js — inside the try block of the runButton click handler,
// change the success line
    renderBacktestResult(body.result);
```

Delete the `renderResult` and `renderEquityCurve` function definitions from the bottom of
`backtest.js` entirely — they now live only in `results-renderer.js`.

- [ ] **Step 3: Manually verify no regression**

Run the three dev servers, open `http://localhost:3000/backtest.html`, run a backtest (e.g.
AAPL + MA Crossover), confirm results render identically to before the extraction — metrics
grid, equity curve, disclosure block all present.

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/results-renderer.js frontend/src/backtest.js
git commit -m "refactor(frontend): extract renderBacktestResult into a shared module"
```

---

### Task 5: `history.html` + `src/history.js` — list, filters, pagination, detail view

**Files:**
- Create: `frontend/history.html`
- Create: `frontend/src/history.js`
- Modify: `frontend/vite.config.js`

**Interfaces:**
- Consumes: `GET /api/backtests` (Task 1), `GET /api/backtests/{id}` (Task 2), `renderBacktestResult` (Task 4), `getToken`/`isLoggedIn`/`clearToken` (existing `auth.js`).
- Produces: the history page. Delete/re-run wiring (Task 6) extends this file.

- [ ] **Step 1: Create the page**

```html
<!-- frontend/history.html -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — History</title>
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
    input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:15px}
    .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
    button{padding:10px 16px;background:var(--accent);color:var(--bg);border:none;
      border-radius:10px;font-weight:700;cursor:pointer;font-size:14px}
    button.secondary{background:transparent;border:1px solid var(--border);color:var(--text)}
    button.danger{background:transparent;border:1px solid var(--red);color:var(--red)}
    button:disabled{opacity:.5;cursor:not-allowed}
    #runsList{margin-top:20px;display:flex;flex-direction:column;gap:10px}
    .run-row{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px 16px;
      display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer}
    .run-row:hover{border-color:var(--accent)}
    .run-row .meta{font-size:13px;color:var(--muted)}
    .run-row .symbol{font-weight:700;font-size:16px}
    .run-row .status{font-size:12px;padding:3px 8px;border-radius:999px}
    .status.complete{background:rgba(34,197,94,.15);color:var(--green)}
    .status.failed{background:rgba(239,68,68,.15);color:var(--red)}
    .status.queued{background:rgba(148,163,184,.15);color:var(--muted)}
    .run-actions{display:flex;gap:8px}
    #loadMore{margin-top:16px;width:100%}
    #empty{color:var(--muted);margin-top:20px;display:none}
    #results{margin-top:24px;display:none}
    #error{color:var(--red);margin-top:14px;display:none}
    .metrics-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:16px}
    .metric-box{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px}
    .metric-box label{margin:0 0 4px}
    .metric-box .value{font-size:20px;font-weight:700}
    .metric-box .value.negative{color:var(--red)}
    #equityCurve{width:100%;height:160px;margin-top:20px;background:#0f172a;border-radius:10px;border:1px solid var(--border)}
    .disclosure{margin-top:20px;padding:16px 18px;border-radius:10px;background:var(--warn-bg);
      border:1px solid var(--warn-border);color:#fde68a;font-size:14px;line-height:1.6}
    .disclosure strong{display:block;margin-bottom:6px;color:#fcd34d}
  </style>
</head>
<body>
<div class="container">
  <a class="back-link" href="/">← Back</a>
  <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
  <h1>Backtest History</h1>
  <p style="color:var(--muted)">Your past backtests. Login required — this list is always scoped to your own account.</p>

  <div class="card">
    <div class="row">
      <div>
        <label for="filterStrategy">Strategy</label>
        <select id="filterStrategy">
          <option value="">All</option>
          <option value="ma_crossover">MA Crossover</option>
          <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
          <option value="method_714">714 Method</option>
        </select>
      </div>
      <div>
        <label for="filterAssetClass">Asset class</label>
        <select id="filterAssetClass">
          <option value="">All</option>
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
          <option value="commodity">Commodity</option>
        </select>
      </div>
      <div>
        <label for="filterStatus">Status</label>
        <select id="filterStatus">
          <option value="">All</option>
          <option value="complete">Complete</option>
          <option value="failed">Failed</option>
          <option value="queued">Queued</option>
        </select>
      </div>
    </div>
  </div>

  <div id="error"></div>
  <div id="empty">No backtests match these filters yet.</div>
  <div id="runsList"></div>
  <button id="loadMore" class="secondary" style="display:none">Load more</button>

  <div id="results" class="card">
    <h2 id="resultTitle"></h2>
    <div class="metrics-grid">
      <div class="metric-box"><label>Total return</label><div class="value" id="mTotalReturn"></div></div>
      <div class="metric-box"><label>Win rate</label><div class="value" id="mWinRate"></div></div>
      <div class="metric-box"><label>Max drawdown</label><div class="value negative" id="mDrawdown"></div></div>
      <div class="metric-box"><label>Sharpe</label><div class="value" id="mSharpe"></div></div>
      <div class="metric-box"><label>Trades</label><div class="value" id="mTrades"></div></div>
      <div class="metric-box"><label>Losing trades</label><div class="value" id="mLosingTrades"></div></div>
    </div>
    <svg id="equityCurve"></svg>
    <div class="disclosure">
      <strong id="dConfidence"></strong>
      <div id="dAttribution"></div>
      <div id="dRisk" style="margin-top:8px"></div>
    </div>
  </div>
</div>
<script type="module" src="/src/history.js"></script>
</body>
</html>
```

Note: the detail section deliberately reuses `id="results"` (the same ID `backtest.html` uses)
so `renderBacktestResult` — which sets `display:block` on `#results` internally — works
completely unmodified on both pages. Using a different ID here would make the shared function
throw (`getElementById('results')` returning `null`) the moment `history.js` called it.

- [ ] **Step 2: Create the page logic**

```js
// frontend/src/history.js
import { getToken, clearToken, isLoggedIn } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';

const API_BASE = 'http://localhost:8000/api';

const authStateEl = document.getElementById('authState');
if (isLoggedIn()) {
  authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
  document.getElementById('logoutLink').addEventListener('click', (e) => {
    e.preventDefault();
    clearToken();
    window.location.reload();
  });
} else {
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
}

const errorEl = document.getElementById('error');
const emptyEl = document.getElementById('empty');
const listEl = document.getElementById('runsList');
const loadMoreButton = document.getElementById('loadMore');
const resultsEl = document.getElementById('results');

const filterStrategy = document.getElementById('filterStrategy');
const filterAssetClass = document.getElementById('filterAssetClass');
const filterStatus = document.getElementById('filterStatus');

let nextPageUrl = null;

function authHeaders() {
  const token = getToken();
  return token ? { Authorization: `Bearer ${token}`, Accept: 'application/json' } : { Accept: 'application/json' };
}

async function loadRuns(url, { reset }) {
  errorEl.style.display = 'none';

  try {
    const response = await fetch(url, { headers: authHeaders() });
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.message || 'Failed to load history');
    }

    if (reset) {
      listEl.innerHTML = '';
    }

    body.data.forEach(renderRunRow);

    nextPageUrl = body.next_page_url;
    loadMoreButton.style.display = nextPageUrl ? 'block' : 'none';
    emptyEl.style.display = reset && body.data.length === 0 ? 'block' : 'none';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

function buildListUrl() {
  const params = new URLSearchParams();
  if (filterStrategy.value) params.set('strategy', filterStrategy.value);
  if (filterAssetClass.value) params.set('asset_class', filterAssetClass.value);
  if (filterStatus.value) params.set('status', filterStatus.value);
  const query = params.toString();
  return `${API_BASE}/backtests${query ? `?${query}` : ''}`;
}

function renderRunRow(run) {
  const row = document.createElement('div');
  row.className = 'run-row';

  const totalReturn = run.results?.metrics?.total_return_pct;
  const returnText = totalReturn == null ? '—' : `${totalReturn.toFixed(2)}%`;

  row.innerHTML = `
    <div>
      <div class="symbol">${run.symbol} <span class="status ${run.status}">${run.status}</span></div>
      <div class="meta">${run.strategy} · ${run.asset_class} · ${new Date(run.created_at).toLocaleString()} · ${returnText}</div>
    </div>
    <div class="run-actions">
      <button class="secondary rerun-btn">Re-run</button>
      <button class="danger delete-btn">Delete</button>
    </div>
  `;

  row.addEventListener('click', (e) => {
    if (e.target.closest('.run-actions')) return;
    showDetail(run.id);
  });

  row.querySelector('.rerun-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    rerun(run);
  });

  row.querySelector('.delete-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    deleteRun(run.id, row);
  });

  listEl.appendChild(row);
}

async function showDetail(id) {
  errorEl.style.display = 'none';

  try {
    const response = await fetch(`${API_BASE}/backtests/${id}`, { headers: authHeaders() });
    const run = await response.json();

    if (!response.ok) {
      throw new Error(run.message || 'Failed to load this run');
    }
    if (!run.results) {
      throw new Error('This run has no results to display yet');
    }

    renderBacktestResult(run.results); // sets #results display:block itself
    resultsEl.scrollIntoView({ behavior: 'smooth' });
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

function rerun(run) {
  sessionStorage.setItem(
    'chartsense_rerun',
    JSON.stringify({
      symbol: run.symbol,
      asset_class: run.asset_class,
      strategy: run.strategy,
      params: run.params,
      start_date: run.start_date,
      end_date: run.end_date,
    })
  );
  window.location.href = '/backtest.html';
}

async function deleteRun(id, rowEl) {
  if (!confirm('Delete this backtest run? This cannot be undone.')) return;

  try {
    const response = await fetch(`${API_BASE}/backtests/${id}`, {
      method: 'DELETE',
      headers: authHeaders(),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.message || 'Failed to delete');
    }

    rowEl.remove();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

[filterStrategy, filterAssetClass, filterStatus].forEach((el) => {
  el.addEventListener('change', () => loadRuns(buildListUrl(), { reset: true }));
});

loadMoreButton.addEventListener('click', () => {
  if (nextPageUrl) loadRuns(nextPageUrl, { reset: false });
});

loadRuns(buildListUrl(), { reset: true });
```

- [ ] **Step 3: Wire the page into the Vite build**

```js
// frontend/vite.config.js — add to rollupOptions.input, alongside the existing entries
        history: resolve(__dirname, 'history.html'),
```

- [ ] **Step 4: Manually verify list, filters, and detail view**

With all three dev servers running and logged in (via `login.html`), run 2–3 backtests with
different strategies/asset classes from `backtest.html`, then open `history.html`. Confirm:
the runs appear, newest first; each filter narrows the list correctly; clicking a row opens
the detail view with the same metrics/equity-curve/disclosure rendering as `backtest.html`;
"Load more" appears only when there are more than 20 runs (fine to skip triggering this
directly at this data volume, just confirm the button stays hidden with fewer than 20).

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/history.html frontend/src/history.js frontend/vite.config.js
git commit -m "feat(frontend): add backtest history page (list, filters, pagination, detail view)"
```

---

### Task 6: Re-run prefill in `backtest.js` + "History" nav links

**Files:**
- Modify: `frontend/src/backtest.js`
- Modify: `frontend/backtest.html`

**Interfaces:**
- Consumes: the `sessionStorage` key `chartsense_rerun` written by Task 5's `rerun()`.
- Produces: end-user-visible UI. No other task depends on this one.

- [ ] **Step 1: Read the re-run prefill on page load**

```js
// frontend/src/backtest.js — add near the top, after the existing element const declarations
// and before the runButton click handler

const rerunData = sessionStorage.getItem('chartsense_rerun');
if (rerunData) {
  sessionStorage.removeItem('chartsense_rerun');
  try {
    const prefill = JSON.parse(rerunData);
    document.getElementById('assetClass').value = prefill.asset_class;
    assetClassSelect.dispatchEvent(new Event('change')); // toggles symbol field visibility
    if (prefill.asset_class === 'commodity') {
      symbolCommoditySelect.value = prefill.symbol;
    } else {
      symbolInput.value = prefill.symbol;
    }
    document.getElementById('strategy').value = prefill.strategy;
    document.getElementById('startDate').value = prefill.start_date;
    document.getElementById('endDate').value = prefill.end_date;
  } catch {
    // Malformed sessionStorage payload — ignore and leave the form at its defaults
    // rather than surfacing an error for something the user didn't directly do.
  }
}
```

- [ ] **Step 2: Add "History" links**

```html
<!-- frontend/backtest.html — change the authState span to include a History link before it -->
  <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
  <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
```

- [ ] **Step 3: Manually verify**

From `history.html`, click "Re-run" on a past run — confirm it navigates to `backtest.html`
with the symbol/asset-class/strategy/dates pre-filled to match that run, and confirm the form
does **not** auto-submit (the "Run backtest" button must still require an explicit click).
Click "History" from `backtest.html` and confirm it navigates back.

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/backtest.js frontend/backtest.html
git commit -m "feat(frontend): wire re-run prefill and History nav link"
```

---

## Plan Self-Review Notes

- **Spec coverage:** list/filter/paginate (Task 1), detail (Task 2), delete (Task 3), shared
  renderer to avoid duplicating result-rendering logic (Task 4), the history page itself
  (Task 5), and re-run-as-prefill plus navigation (Task 6) all map directly to the design
  doc's in-scope items. Anonymous history and in-place param editing (both explicitly out of
  scope) are not touched by any task.
- **Consistency check:** every backend task uses the same `$request->user('sanctum')`
  ownership pattern established in the auth slice — no drift back to the unguarded
  `$request->user()` that caused a real bug there. `renderBacktestResult`'s DOM-ID contract
  (Task 4) is honored identically by `history.html`'s markup (Task 5) and by `backtest.html`'s
  pre-existing markup, so the same function works unmodified in both places.
- **No placeholders:** every step has complete, real code.
