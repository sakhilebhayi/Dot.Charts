# Pairs Trading Strategy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `pairs_trading` as a new two-symbol, cointegration-gated stat-arb strategy, wired all the way through the analytics service's two-symbol dispatch path, Laravel validation/attribution, and both frontend surfaces (a conditional Symbol B field on the backtest form, and a symbol_b-aware history row/filter).

**Architecture:** A new strategy module (`analytics/strategies/pairs_trading.py`) that departs from every other strategy's single-`df` contract — it takes `(df_a, df_b, params)` and trades the cointegrated spread as a synthetic single instrument, per the design spec's simplification. `STRATEGY_REGISTRY` gains a `requires_symbol_b` flag `main.py`'s `/backtest` endpoint branches on, fetching a second symbol's OHLCV before dispatch. `symbol_b` travels as a `params` sub-key end-to-end (no new DB column, no new Pydantic field) — persisted in `BacktestRun.params` (already a JSON column) and echoed back in the analytics service's response `params`, which is how `DisclosureFormatter` finds it for attribution without any schema change.

**Tech Stack:** Python `pandas`/`vectorbt` (existing) + `statsmodels` (new, for the Engle-Granger cointegration test), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- Cointegration is a correctness gate, not a tunable a caller can silently skip: if `coint()`'s p-value exceeds `coint_pvalue_max` (default 0.05), `generate_signals` returns all-`False` entries/exits rather than trading a spurious spread (per spec's Strategy Definition, item 1).
- The hedge ratio is computed as a rolling closed-form OLS slope (`price_a.rolling(lookback).cov(price_b) / price_b.rolling(lookback).var()`), not a per-bar `statsmodels.OLS` refit loop — mathematically the same single-regressor slope, much cheaper. **Refinement over the spec's pseudocode**, which named `statsmodels.OLS` for this step; `coint()` is still the statsmodels call that matters (the cointegration test itself).
- **Refinement over the spec's pseudocode:** `vbt.Portfolio.from_signals` treats its price argument as a literal tradeable price. The raw spread (`price_a - hedge_ratio * price_b`) is a difference and can go negative — feeding it directly would produce nonsensical negative-price portfolio math. `run()` rebases the spread onto `price_a`'s starting price level (`spread + df_a["close"].iloc[0]`) before handing it to the engine. This is a constant additive shift and changes no entry/exit timing (the z-score it's derived from is invariant to adding a constant to the spread).
- **Locked in during planning, replacing the spec's proposed schema change:** `symbol_b` is NOT a new `BacktestRequest`/`BacktestResult` Pydantic field, NOT a new `backtest_runs` DB column, and NOT a `'params.symbol_b' => '...'` Laravel dot-path validation rule. It travels inside the existing freeform `params` dict/column throughout, validated in `BacktestController::store` with a manual conditional check instead of a dot-path rule — **a dot-path rule on one `params` sub-key makes Laravel's `validate()` strip every other `params` sub-key not covered by an explicit rule, which breaks the `custom` strategy's arbitrary rule-object params.** This was caught by the existing `custom`-strategy test regressing during Task 3 and is the main gotcha to watch for if this pattern is ever copied elsewhere.
- Disclosure attribution states the synthetic-spread caveat explicitly (per spec's Design Decision section) — this is not optional flavor text, it's the loss-honesty/no-false-precision principle applied to a strategy whose reported metrics don't describe two separately-filled legs.

---

### Task 1: Pairs trading strategy module

**Files:**
- Create: `analytics/strategies/pairs_trading.py`
- Test: `analytics/tests/test_pairs_trading.py`
- Modify: `analytics/requirements.txt`

**Interfaces:**
- Consumes: `statsmodels.tsa.stattools.coint` (new dependency).
- Produces: `DEFAULT_PARAMS = {"lookback": 20, "entry_z": 2.0, "exit_z": 0.5, "stop_z": 4.0, "coint_pvalue_max": 0.05}`, `generate_signals(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]` (entries, exits, spread), `run(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> vbt.Portfolio` — Task 2 (registry + engine wrapper) relies on this exact two-`df` signature, which is why it needs its own dispatch path rather than a plain registry entry.

- [ ] **Step 1: Add the new dependency**

Append to `analytics/requirements.txt`:

```
statsmodels>=0.14
```

Install it into the existing venv: `cd analytics && .venv/bin/pip install statsmodels`

- [ ] **Step 2: Write the failing test**

```python
# analytics/tests/test_pairs_trading.py
import numpy as np
import pandas as pd
from strategies.pairs_trading import generate_signals, run, DEFAULT_PARAMS


def _cointegrated_pair_with_divergence() -> tuple[pd.DataFrame, pd.DataFrame]:
    # B drifts like a random walk; A = B + a mean-reverting AR(1) spread,
    # so A and B are genuinely cointegrated by construction. One large,
    # one-off shock is injected into the spread at bar 70 -- the AR(1)
    # process pulls it back toward zero afterward, giving a deterministic
    # divergence-then-reversion event. Fixed seed (3) for reproducibility.
    n = 150
    idx = pd.date_range("2023-01-01", periods=n, freq="D")
    rng = np.random.default_rng(3)

    b_close = pd.Series(100 + np.cumsum(rng.normal(0.03, 0.25, n)), index=idx)

    spread = np.zeros(n)
    for i in range(1, n):
        spread[i] = 0.7 * spread[i - 1] + rng.normal(0, 0.15)
    spread[70] -= 4.0

    a_close = pd.Series(b_close.values + spread, index=idx)

    df_a = pd.DataFrame({"open": a_close, "high": a_close, "low": a_close, "close": a_close, "volume": 1000})
    df_b = pd.DataFrame({"open": b_close, "high": b_close, "low": b_close, "close": b_close, "volume": 1000})
    return df_a, df_b


def _uncorrelated_pair() -> tuple[pd.DataFrame, pd.DataFrame]:
    # Two independent random walks -- not cointegrated. Fixed seed (11).
    n = 150
    idx = pd.date_range("2023-01-01", periods=n, freq="D")
    rng = np.random.default_rng(11)

    a_close = pd.Series(100 + np.cumsum(rng.normal(0.05, 0.5, n)), index=idx)
    b_close = pd.Series(50 + np.cumsum(rng.normal(-0.02, 0.6, n)), index=idx)

    df_a = pd.DataFrame({"open": a_close, "high": a_close, "low": a_close, "close": a_close, "volume": 1000})
    df_b = pd.DataFrame({"open": b_close, "high": b_close, "low": b_close, "close": b_close, "volume": 1000})
    return df_a, df_b


def test_generate_signals_fires_entry_on_divergence():
    df_a, df_b = _cointegrated_pair_with_divergence()

    entries, exits, _spread = generate_signals(df_a, df_b, DEFAULT_PARAMS)

    assert entries.iloc[70], "expected entry when the z-score crosses below -entry_z at the injected shock"
    assert not entries.iloc[:70].any(), "no entry before the shock bar"


def test_run_produces_one_round_trip_trade_through_the_divergence_and_reversion():
    # Signal-level assertions alone don't capture real behavior here --
    # the exit condition can fire spuriously while flat (ordinary AR(1)
    # noise crosses the +-exit_z band often), which vectorbt correctly
    # ignores since there's no open position to close. Assert on the
    # actual portfolio's trades instead, which is what a caller acts on.
    df_a, df_b = _cointegrated_pair_with_divergence()

    portfolio = run(df_a, df_b, DEFAULT_PARAMS)
    trades = portfolio.trades.records_readable

    assert len(trades) == 1
    assert str(trades.iloc[0]["Entry Timestamp"]) == "2023-03-12 00:00:00"
    assert str(trades.iloc[0]["Exit Timestamp"]) == "2023-03-24 00:00:00"


def test_generate_signals_returns_no_trades_for_uncointegrated_pair():
    df_a, df_b = _uncorrelated_pair()

    entries, exits, _spread = generate_signals(df_a, df_b, DEFAULT_PARAMS)

    assert not entries.any(), "the cointegration gate should reject an unrelated pair before any signal fires"
    assert not exits.any()
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_pairs_trading.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.pairs_trading'`

- [ ] **Step 4: Write the implementation**

```python
# analytics/strategies/pairs_trading.py
import pandas as pd
import vectorbt as vbt
from statsmodels.tsa.stattools import coint

DEFAULT_PARAMS = {
    "lookback": 20,
    "entry_z": 2.0,
    "exit_z": 0.5,
    "stop_z": 4.0,
    "coint_pvalue_max": 0.05,
}


def generate_signals(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Returns (entries, exits, spread) -- spread is the synthetic series
    run() feeds to vbt.Portfolio.from_signals (after rebasing to a positive
    price level; see run())."""
    lookback = params.get("lookback", DEFAULT_PARAMS["lookback"])
    entry_z = params.get("entry_z", DEFAULT_PARAMS["entry_z"])
    exit_z = params.get("exit_z", DEFAULT_PARAMS["exit_z"])
    stop_z = params.get("stop_z", DEFAULT_PARAMS["stop_z"])
    coint_pvalue_max = params.get("coint_pvalue_max", DEFAULT_PARAMS["coint_pvalue_max"])

    price_a = df_a["close"]
    price_b = df_b["close"]

    # Correctness gate, not a tunable knob a caller can silently disable:
    # if the two symbols aren't genuinely cointegrated over this window,
    # trading their "spread" is trading noise, not a mean-reverting
    # relationship. Fail closed -- no signals at all rather than a
    # spurious spread.
    _, pvalue, _ = coint(price_a, price_b)
    if pvalue > coint_pvalue_max:
        empty = pd.Series(False, index=price_a.index)
        return empty, empty, price_a - price_b

    # Rolling hedge ratio via the closed-form single-regressor OLS slope
    # (cov/var), refit every bar over the trailing `lookback` window --
    # equivalent to a rolling univariate OLS of price_a on price_b without
    # an explicit per-bar regression loop, and avoids a second, heavier
    # statsmodels call on every bar.
    hedge_ratio = price_a.rolling(lookback).cov(price_b) / price_b.rolling(lookback).var()
    spread = price_a - hedge_ratio * price_b
    z = (spread - spread.rolling(lookback).mean()) / spread.rolling(lookback).std()

    entries = (z < -entry_z) & (z.shift(1) >= -entry_z)
    mean_revert_exit = (z > -exit_z) & (z.shift(1) <= -exit_z)
    stop_exit = z < -stop_z
    exits = mean_revert_exit | stop_exit

    return entries.fillna(False), exits.fillna(False), spread


def run(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits, spread = generate_signals(df_a, df_b, params)

    # vbt.Portfolio.from_signals treats its price argument as a literal
    # tradeable price -- the raw spread is a difference, not a price, and
    # can go negative. Rebase it onto price_a's starting level before
    # handing it to the engine. This is a constant additive shift, so it
    # changes no entry/exit timing (those depend only on the z-score,
    # which is invariant to adding a constant to the spread it's computed
    # from) -- it only makes the series look like a normal positive-valued
    # instrument price to the portfolio engine.
    synthetic_price = spread + df_a["close"].iloc[0]
    return vbt.Portfolio.from_signals(synthetic_price, entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_pairs_trading.py -v`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/pairs_trading.py analytics/tests/test_pairs_trading.py analytics/requirements.txt
git commit -m "feat(strategies): add pairs trading (cointegration stat-arb) strategy"
```

---

### Task 2: Two-symbol dispatch — registry, engine wrapper, endpoint

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Modify: `analytics/schemas.py`
- Modify: `analytics/engines/vectorbt_engine.py`
- Modify: `analytics/main.py`
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `pairs_trading.DEFAULT_PARAMS`/`pairs_trading` module and its `(df_a, df_b, params)` signature from Task 1.
- Produces: `STRATEGY_REGISTRY["pairs_trading"]` with `"requires_symbol_b": True`; `run_vectorbt_pairs(strategy_module, df_a, df_b, params) -> dict` in the engine module — later tasks (Laravel) rely on the `"pairs_trading"` string key and on `params.symbol_b` being echoed back in the response's `params` field.

- [ ] **Step 1: Write the failing tests**

Append to `analytics/tests/test_backtest_endpoint.py`:

```python
def test_backtest_pairs_trading_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "pairs_trading",
            "params": {"symbol_b": "MSFT"},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "pairs_trading"
    assert body["params"]["symbol_b"] == "MSFT"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_pairs_trading_without_symbol_b_returns_422(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "pairs_trading",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422
    assert "symbol_b" in response.json()["detail"]
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k pairs`
Expected: FAIL — `pairs_trading` isn't a recognized `StrategyName` yet (Pydantic validation error, not a clean 422 from the handler).

- [ ] **Step 3: Register the strategy and add the engine wrapper**

In `analytics/strategies/__init__.py`, add the import and a registry entry:

```python
from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion, momentum, pairs_trading, custom
```

```python
    "pairs_trading": {
        "engine": "vectorbt",
        "module": pairs_trading,
        "default_params": pairs_trading.DEFAULT_PARAMS,
        "interval": "1d",
        # Handled by a separate two-symbol dispatch path in main.py --
        # every other vectorbt strategy consumes a single df.
        "requires_symbol_b": True,
    },
```

(Insert the import in the existing import line and the registry entry in the existing dict, in whatever position keeps the file's existing ordering — right before `"custom"` is fine, matching precedent.)

In `analytics/schemas.py`, add `"pairs_trading"` to `StrategyName`:

```python
StrategyName = Literal[
    "ma_crossover", "rsi_mean_reversion", "breakout", "bollinger_mean_reversion", "momentum",
    "pairs_trading", "custom", "method_714",
]
```

In `analytics/engines/vectorbt_engine.py`, add:

```python
def run_vectorbt_pairs(strategy_module, df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> dict:
    portfolio = strategy_module.run(df_a, df_b, params)
    return compute_metrics_from_portfolio(portfolio)
```

- [ ] **Step 4: Add the two-symbol dispatch branch in `main.py`**

Change the import:

```python
from engines.vectorbt_engine import run_vectorbt, run_vectorbt_pairs
```

In the `/backtest` handler, insert a new branch **before** the existing `if entry["engine"] == "vectorbt":` check:

```python
    if entry.get("requires_symbol_b"):
        symbol_b = params.get("symbol_b")
        if not symbol_b:
            raise HTTPException(status_code=422, detail="pairs_trading requires params.symbol_b")
        try:
            df_b = fetch_ohlcv_cached(
                symbol_b,
                request.asset_class,
                request.start_date,
                request.end_date,
                interval=entry["interval"],
            )
        except DataFetchError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
        result = run_vectorbt_pairs(entry["module"], df, df_b, params)
    elif entry["engine"] == "vectorbt":
        try:
            result = run_vectorbt(entry["module"], df, params)
        except InvalidStrategyParamsError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
    else:
```

(The rest of the `else` branch, for `method_714`/backtrader, is unchanged.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v`
Expected: PASS (all tests in the file, including the 2 new ones)

- [ ] **Step 6: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 7: Commit**

```bash
git add analytics/strategies/__init__.py analytics/schemas.py analytics/engines/vectorbt_engine.py analytics/main.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(strategies): wire pairs_trading's two-symbol dispatch through the backtest endpoint"
```

---

### Task 3: Laravel validation + attribution

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: the string key `"pairs_trading"` and the `params.symbol_b` convention from Task 2.
- Produces: nothing new for later tasks — this task's effect is entirely in validation/attribution behavior.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/BacktestControllerTest.php`, inside the `BacktestControllerTest` class:

```php
    public function test_store_accepts_pairs_trading_strategy_with_symbol_b(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'pairs_trading',
                'params' => ['symbol_b' => 'MSFT', 'lookback' => 20, 'entry_z' => 2.0, 'exit_z' => 0.5, 'stop_z' => 4.0, 'coint_pvalue_max' => 0.05],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 5.0,
                    'win_rate_pct' => 100.0,
                    'max_drawdown_pct' => -1.5,
                    'sharpe_ratio' => 0.8,
                    'trade_count' => 1,
                    'losing_trade_count' => 0,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'pairs_trading',
            'params' => ['symbol_b' => 'MSFT'],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'Pairs Trading (Stat-Arb)') && str_contains($attribution, 'vs. MSFT');
        });
    }

    public function test_store_rejects_pairs_trading_strategy_without_symbol_b(): void
    {
        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'pairs_trading',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['params.symbol_b']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=pairs_trading`
Expected: FAIL — `pairs_trading` isn't in the `strategy` validation allow-list yet, so both requests 422 for the wrong reason (or the first one fails `assertOk()`).

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, add the `use` import:

```php
use Illuminate\Validation\ValidationException;
```

Update the `strategy` validation rule:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,momentum,pairs_trading,custom',
```

**Do not** add a `'params.symbol_b' => '...'` rule alongside `'params' => 'nullable|array'` — a dot-path rule on one `params` sub-key makes Laravel's `validate()` strip every other `params` sub-key not covered by an explicit rule, silently breaking the `custom` strategy's arbitrary rule-object params (this regresses `test_store_accepts_custom_strategy_with_rule_params` if done this way — confirmed by running the full suite after making this exact mistake during initial implementation). Instead, add a manual check right after the `$request->validate([...])` call:

```php
        // pairs_trading is the one strategy that needs a second instrument
        // -- stored inside params (already a persisted JSON column) rather
        // than adding a dedicated symbol_b column, since nothing else
        // needs to query on it independently. This is a manual check, not
        // a 'params.symbol_b' => '...' validation rule: adding a
        // dot-notation rule for one params sub-key makes Laravel's
        // validate() strip every *other* params sub-key not covered by an
        // explicit rule -- which would silently break the custom
        // strategy's arbitrary rule-object params.
        if ($validated['strategy'] === 'pairs_trading' && empty($validated['params']['symbol_b'] ?? null)) {
            throw ValidationException::withMessages([
                'params.symbol_b' => 'The params.symbol_b field is required when strategy is pairs_trading.',
            ]);
        }
```

Insert this between the `$validated = $request->validate([...]);` block and the `$run = BacktestRun::create([...]);` call.

In `backend/app/Services/DisclosureFormatter.php`, add to `STRATEGY_LABELS`:

```php
        'pairs_trading' => 'Pairs Trading (Stat-Arb)',
```

And add a new branch in `attribution()`, alongside the existing `method_714` branch:

```php
        if ($strategyKey === 'pairs_trading') {
            $symbolB = $backtestResult['params']['symbol_b'] ?? '?';
            $attribution .= sprintf(
                ' vs. %s. Metrics describe the cointegrated spread traded as a single synthetic '
                . 'instrument, not two separately filled and financed legs — this is a backtest of '
                . 'the signal, not broker-accurate two-leg execution accounting.',
                $symbolB
            );
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=pairs_trading`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions — **specifically confirm `test_store_accepts_custom_strategy_with_rule_params` still passes**, since that's the test the dot-path-rule mistake above would break.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/app/Services/DisclosureFormatter.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): accept pairs_trading strategy with symbol_b"
```

---

### Task 4: Frontend — conditional Symbol B field, dropdowns, history row

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/src/backtest.js`
- Modify: `frontend/history.html`
- Modify: `frontend/src/history.js`

**Interfaces:**
- Consumes: the string key `"pairs_trading"` from Task 2, and the `params.symbol_b` convention from Task 3.
- Produces: nothing new for later tasks — this is the final task in the plan.

- [ ] **Step 1: Add the strategy option and a conditional Symbol B field to `backtest.html`**

Add the option to the strategy `<select>`:

```html
      <option value="momentum">Momentum</option>
      <option value="pairs_trading">Pairs Trading (Stat-Arb)</option>
      <option value="method_714">714 Method</option>
```

Add a new field, hidden by default, right after the strategy `<select>` and before the Run button:

```html
    <div id="symbolBRow" style="display:none">
      <label for="symbolB">Symbol B (paired against Symbol above)</label>
      <input id="symbolB" placeholder="MSFT" />
    </div>
```

- [ ] **Step 2: Wire the toggle and payload in `backtest.js`**

Add element refs and a change listener near the other element refs at the top of the file:

```javascript
const symbolBRow = document.getElementById('symbolBRow');
const symbolBInput = document.getElementById('symbolB');

strategySelect.addEventListener('change', () => {
  symbolBRow.style.display = strategySelect.value === 'pairs_trading' ? '' : 'none';
});
```

Update the `payload` construction inside the `runButton` click handler:

```javascript
  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: isCustom ? 'custom' : selectedStrategy,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: isCustom
      ? savedStrategyRules[customId]
      : selectedStrategy === 'pairs_trading'
        ? { symbol_b: symbolBInput.value.trim() }
        : {},
  };
```

- [ ] **Step 3: Update `history.html`'s strategy filter dropdown**

```html
          <option value="momentum">Momentum</option>
          <option value="pairs_trading">Pairs Trading (Stat-Arb)</option>
          <option value="method_714">714 Method</option>
```

- [ ] **Step 4: Show Symbol B in the history row**

In `history.js`'s `renderRunRow`, add a second span next to the existing `symbol-text` span:

```html
      <div class="symbol"><span class="symbol-text"></span><span class="symbol-b-text"></span> <span class="status ${run.status}">${run.status}</span></div>
```

And populate it via `textContent` (never `innerHTML`) right after the existing `symbol-text` assignment — `run.params.symbol_b` is freeform user text with the same XSS exposure as `run.symbol`, which is why the surrounding code already treats `run.symbol` this way:

```javascript
  row.querySelector('.symbol-text').textContent = run.symbol;
  // pairs_trading is the one strategy with a second instrument -- it
  // lives in run.params.symbol_b (freeform user text, same as run.symbol),
  // so this goes through textContent too, not the innerHTML template above.
  if (run.strategy === 'pairs_trading' && run.params?.symbol_b) {
    row.querySelector('.symbol-b-text').textContent = ` vs. ${run.params.symbol_b}`;
  }
```

- [ ] **Step 5: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/backtest.html`, select "Pairs Trading (Stat-Arb)" and confirm the "Symbol B" field appears. Fill Symbol=`AAPL`, Symbol B=`MSFT`, a date range of at least a few months, click "Run backtest", and confirm it renders a result with no console errors — the disclosure text should read "...on AAPL vs. MSFT. Metrics describe the cointegrated spread...".
3. Open `http://localhost:3000/history.html` and confirm "Pairs Trading (Stat-Arb)" appears in the strategy filter dropdown.
4. Stop the dev servers.

- [ ] **Step 6: Commit**

```bash
git add frontend/backtest.html frontend/src/backtest.js frontend/history.html frontend/src/history.js
git commit -m "feat(frontend): add pairs trading Symbol B field and history display"
```
