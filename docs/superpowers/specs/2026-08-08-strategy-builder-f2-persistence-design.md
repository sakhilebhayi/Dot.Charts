# Strategy Builder — F2: Persistence — Design

**Subsystem:** F (from the Dot.Charts gap-closure audit), sub-project 2 of 4

## Problem

F1 added a `custom` strategy that interprets a JSON rule schema and runs it
through the existing vectorbt pipeline, but there's no way to save a
built strategy for reuse — every backtest requires re-sending the full
rule JSON inline. F2 adds save/load/list/delete for user-built strategies.

## Scope

Recap of the 4-part decomposition established in F1's spec:
1. F1 — rule model + execution engine (done).
2. **F2 (this spec)** — persistence: save/load/list/delete.
3. F3 — the visual canvas that produces F1's rule JSON.
4. F4 — integration into `backtest.html`'s strategy dropdown, History, etc.

F2 is backend-only, matching F1. No frontend UI is added in this slice —
saved strategies become reachable through the product UI once F3/F4 exist,
same reasoning as F1's scope boundary.

## Auth

Every operation (create/list/show/delete) requires login —
`auth:sanctum` on all four endpoints, no anonymous case. A saved, named
strategy is a personal asset with no value if it can't be retrieved
later; this differs from `BacktestRun`, where an anonymous one-off
backtest is meaningful even without an account. This matches the
established "login required, no exceptions" pattern already chosen for
the backtest-history endpoints (`index`/`show`/`destroy`).

## Data Model

```php
Schema::create('custom_strategies', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->json('rules'); // F1's {entry: {...}, exit: {...}} schema
    $table->timestamps();
});
```

`CustomStrategy` Eloquent model: `fillable = ['user_id', 'name', 'description', 'rules']`,
`casts = ['rules' => 'array']`, `belongsTo(User::class)` — mirrors
`BacktestRun`'s existing shape exactly.

## Save-Time Validation

`evaluate_rule` (F1) needs a real `pd.DataFrame` to resolve indicator
operands against — it can't validate rule *structure* from JSON alone
without also exercising the indicator-resolution code path. Rather than
duplicating that logic in PHP (two places to keep in sync as the rule
schema evolves), a new lightweight analytics endpoint reuses it directly:

```python
POST /validate-rule
{"rules": {"entry": {...}, "exit": {...}}}
```

Internally, this builds a small synthetic in-memory DataFrame (no live
market-data fetch — just enough synthetic bars, e.g. 250, for any
indicator length used in practice to resolve without erroring on
insufficient history) and calls `evaluate_rule` against both the `entry`
and `exit` rules, catching `InvalidStrategyParamsError`. Response:

```json
{"valid": true}
```
or
```json
{"valid": false, "error": "Unknown comparator: not_a_real_comparator"}
```

This returns HTTP 200 either way — "the rule is invalid" is a normal,
successfully-answered validation question, not a service error. (Contrast
with `/backtest`, where an invalid rule genuinely is a client error,
because the request as a whole cannot be fulfilled — `/validate-rule`'s
entire purpose is to answer "is this valid," so a `false` answer is
success, not failure.)

`CustomStrategyController::store` calls `/validate-rule` (via a new
`AnalyticsServiceClient::validateRule` method, mirroring `runBacktest`'s
existing shape) before persisting. If `valid: false`, `store` returns a
422 with the analytics-provided error message. If the analytics call
itself fails (connection error, 5xx), `store` returns a 502 — distinct
from 422, since that's an infrastructure failure, not "your rule is
malformed."

## Endpoint Contracts

- **`POST /api/strategies`** — `{name: string, description?: string, rules: {entry, exit}}`.
  Laravel validates `name` (`required|string|max:100`) and `rules`
  (`required|array`) are present, then calls `/validate-rule`. Persists
  scoped to `$request->user('sanctum')->id` (the Sanctum-guard fix from
  the Auth slice — `$request->user()` alone resolves the wrong guard on
  routes without `auth:sanctum` middleware, though this route always has
  it, this is still the correct explicit call per the codebase's existing
  convention). Returns 201 with the created record.
- **`GET /api/strategies`** — `CustomStrategy::where('user_id', ...)->orderByDesc('created_at')->paginate(20)`,
  identical shape to `BacktestController::index`.
- **`GET /api/strategies/{id}`** — 404 if not found or not owned by the
  requester, matching `BacktestController::show`'s ownership-check
  pattern.
- **`DELETE /api/strategies/{id}`** — same ownership check, then delete.
- No update/edit endpoint in this slice — editing is delete-and-recreate
  for now; a dedicated `PATCH` can be added later if F3/F4's UI actually
  needs in-place editing rather than replace (YAGNI).

## Testing

- **`analytics/tests/test_validate_rule_endpoint.py`**: a `/validate-rule`
  smoke test with a valid rule (`{"valid": true}`) and an invalid one
  (`{"valid": false, "error": "..."}`, still HTTP 200).
- **`backend/tests/Feature/CustomStrategyControllerTest.php`**: `store`
  succeeds with a valid rule and persists a row scoped to the
  authenticated user; `store` returns 422 for a rule the mocked analytics
  response marks invalid; `index` returns only the authenticated user's
  strategies (not another user's); `show`/`destroy` return 404 for
  another user's strategy and do not delete it; all four endpoints
  require authentication (401/redirect for an unauthenticated request).
