# Momentum Strategy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `momentum` as a new vectorbt-based strategy preset, wired all the way through the analytics service, Laravel validation/attribution, and both frontend strategy dropdowns.

**Architecture:** One new strategy module under `analytics/strategies/`, matching the existing `DEFAULT_PARAMS` / `generate_signals(df, params)` / `run(df, params)` contract exactly, registered in `STRATEGY_REGISTRY`. Every other place that already hand-enumerates strategy names (Laravel's `store` validation, `DisclosureFormatter`'s labels, both frontend `<select>`s) gets the same name added — the same integration pattern the `breakout`/`bollinger_mean_reversion` addition already established.

**Tech Stack:** Python `pandas`/`vectorbt` (both existing dependencies, no new ones), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- Long-only, matching every existing strategy (per spec's Strategy Definition section).
- The strategy needs at least `lookback` (252 by default) bars of history before it can produce its first signal (per spec's Strategy Definition section) — this is a real, user-facing limitation, not a bug, and belongs in the strategy's UI description text.
- No changes to `main.py`'s `/backtest` endpoint or `engines/vectorbt_engine.py` — both are already strategy-agnostic (per spec's Module Contract section).
- **Refinement from the spec, locked in during planning:** the spec's pseudocode paired a `lookback`-window momentum filter with a `roc_window`-crossover as two independent crossing events required on the same bar. Because `roc_window` is much shorter than `lookback`, its crossover fires long before the slower filter's does in any real trending series, so requiring both to cross simultaneously makes entries fire close to never. This plan instead uses `roc` as a level confirmation (`roc > roc_threshold`, no crossing requirement) gated on `mom`'s own crossover for entry timing — same inputs and same parameters as the spec, corrected combination logic. Documented inline in Task 1's implementation step.

---

### Task 1: Momentum strategy module

**Files:**
- Create: `analytics/strategies/momentum.py`
- Test: `analytics/tests/test_momentum.py`

**Interfaces:**
- Consumes: nothing new (uses `pandas`, `vectorbt` only, same as `ma_crossover.py`).
- Produces: `DEFAULT_PARAMS = {"lookback": 252, "skip": 21, "roc_window": 10, "roc_threshold": 0.0}`, `generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]`, `run(df: pd.DataFrame, params: dict) -> vbt.Portfolio` — the same three names Task 2 (registry) relies on.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_momentum.py
import pandas as pd
from strategies.momentum import generate_signals

# Smaller than DEFAULT_PARAMS so a 100-bar synthetic fixture can exercise
# a real crossover -- production defaults (lookback=252) need a year of
# history, out of scope for a unit-test fixture.
PARAMS = {"lookback": 20, "skip": 5, "roc_window": 5, "roc_threshold": 0.0}


def _uptrend_after_flat_df() -> pd.DataFrame:
    # 40 flat bars (so the 20-bar lookback has clean, non-trending history
    # to compare against), then a steady uptrend -- deterministic single
    # momentum crossover, no mocks needed.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 40
    uptrend = [100.0 + k * 1.5 for k in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _downtrend_after_flat_df() -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 40
    downtrend = [100.0 - k * 1.5 for k in range(1, 61)]
    close = pd.Series(flat + downtrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_momentum_crossover():
    df = _uptrend_after_flat_df()

    entries, exits = generate_signals(df, PARAMS)

    assert entries.iloc[45], "expected entry once the lookback-window momentum crosses above zero"
    assert not entries.iloc[:45].any(), "no entry during the flat section or before the crossover bar"
    assert entries.sum() == 1


def test_generate_signals_fires_exit_on_momentum_breakdown():
    df = _downtrend_after_flat_df()

    entries, exits = generate_signals(df, PARAMS)

    assert exits.iloc[45], "expected exit once the lookback-window momentum crosses below zero"
    assert not exits.iloc[:45].any()
    assert exits.sum() == 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_momentum.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.momentum'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/strategies/momentum.py
import pandas as pd
import vectorbt as vbt

DEFAULT_PARAMS = {
    "lookback": 252,
    "skip": 21,
    "roc_window": 10,
    "roc_threshold": 0.0,
}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    lookback = params.get("lookback", DEFAULT_PARAMS["lookback"])
    skip = params.get("skip", DEFAULT_PARAMS["skip"])
    roc_window = params.get("roc_window", DEFAULT_PARAMS["roc_window"])
    roc_threshold = params.get("roc_threshold", DEFAULT_PARAMS["roc_threshold"])

    # 12-month-minus-most-recent-month momentum: skip excludes the most
    # recent `skip` bars from the lookback measurement, the standard
    # formulation used to avoid short-term reversal contaminating a
    # longer-term trend read.
    mom = df["close"].shift(skip) / df["close"].shift(lookback) - 1
    roc = df["close"].pct_change(roc_window)

    # Entry fires on mom's own crossover (the trend filter turning on),
    # with roc used as a level confirmation (short-term momentum already
    # positive) rather than requiring both to cross on the exact same bar.
    # roc, being a much shorter window than lookback, typically turns
    # positive long before the slower lookback-window mom filter does --
    # requiring simultaneous crossings would make entries fire close to
    # never on any real trending series.
    mom_cross_up = (mom > 0) & (mom.shift(1) <= 0)
    entries = mom_cross_up & (roc > roc_threshold)

    exits = (mom < 0) & (mom.shift(1) >= 0)

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_momentum.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/momentum.py analytics/tests/test_momentum.py
git commit -m "feat(strategies): add momentum strategy"
```

---

### Task 2: Register momentum + `/backtest` endpoint smoke test

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `momentum.DEFAULT_PARAMS`/`momentum` module from Task 1.
- Produces: `STRATEGY_REGISTRY["momentum"]` entry — later tasks (Laravel, frontend) rely on this exact string key.

- [ ] **Step 1: Write the failing test**

Append to `analytics/tests/test_backtest_endpoint.py`:

```python
def test_backtest_momentum_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "momentum",
            "params": {"lookback": 20, "skip": 5, "roc_window": 5, "roc_threshold": 0.0},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "momentum"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k momentum`
Expected: FAIL with a 422 response (`assert 422 == 200`), since `STRATEGY_REGISTRY` doesn't recognize `"momentum"` yet.

- [ ] **Step 3: Write the implementation**

Replace the full contents of `analytics/strategies/__init__.py`:

```python
from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion, momentum, custom
from .method_714.strategy import Method714Strategy

STRATEGY_REGISTRY = {
    "ma_crossover": {
        "engine": "vectorbt",
        "module": ma_crossover,
        "default_params": ma_crossover.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "rsi_mean_reversion": {
        "engine": "vectorbt",
        "module": rsi_mean_reversion,
        "default_params": rsi_mean_reversion.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "breakout": {
        "engine": "vectorbt",
        "module": breakout,
        "default_params": breakout.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "bollinger_mean_reversion": {
        "engine": "vectorbt",
        "module": bollinger_mean_reversion,
        "default_params": bollinger_mean_reversion.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "momentum": {
        "engine": "vectorbt",
        "module": momentum,
        "default_params": momentum.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "custom": {
        "engine": "vectorbt",
        "module": custom,
        "default_params": custom.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "method_714": {
        "engine": "backtrader",
        "strategy_cls": Method714Strategy,
        "default_params": {},
        # method_714's session logic needs intraday bars — daily bars are
        # always midnight and never fall inside a session window.
        "interval": "1h",
    },
}
```

(This preserves every existing entry as-is — `momentum` is inserted after `bollinger_mean_reversion` and before `custom`. If the real file on disk already differs slightly from this listing — e.g. import order — keep its existing structure and only add the `momentum` import and registry entry in the equivalent place.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v`
Expected: PASS (all tests in the file, including the new one)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/__init__.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(strategies): register momentum in STRATEGY_REGISTRY"
```

---

### Task 3: Laravel validation + attribution label

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: the string key `"momentum"` from Task 2 (must match exactly, or a valid Python-service strategy would still 422 at the Laravel layer).
- Produces: nothing new for later tasks — this task's effect is entirely in validation/label behavior.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/BacktestControllerTest.php`, inside the `BacktestControllerTest` class:

```php
    public function test_store_accepts_momentum_strategy(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'momentum',
                'params' => ['lookback' => 252, 'skip' => 21, 'roc_window' => 10, 'roc_threshold' => 0.0],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 3.0,
                    'win_rate_pct' => 40.0,
                    'max_drawdown_pct' => -2.0,
                    'sharpe_ratio' => 0.5,
                    'trade_count' => 8,
                    'losing_trade_count' => 5,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'momentum',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'Momentum');
        });
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_store_accepts_momentum_strategy`
Expected: FAIL — the `strategy` field's `in:...` validation rule rejects `momentum` with a 422, so `$response->assertOk()` fails.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, change:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,custom',
```

to:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,momentum,custom',
```

In `backend/app/Services/DisclosureFormatter.php`, change:

```php
    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
        'breakout' => 'Breakout (Donchian)',
        'bollinger_mean_reversion' => 'Bollinger Mean-Reversion',
        'custom' => 'Custom Strategy',
    ];
```

to:

```php
    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
        'breakout' => 'Breakout (Donchian)',
        'bollinger_mean_reversion' => 'Bollinger Mean-Reversion',
        'momentum' => 'Momentum',
        'custom' => 'Custom Strategy',
    ];
```

(If either file's current contents differ in ordering or exact whitespace from what's shown here, keep the existing structure and just add the one new line/entry in the equivalent place — the added value on each side is what matters, not the surrounding formatting.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=test_store_accepts_momentum_strategy`
Expected: PASS

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/app/Services/DisclosureFormatter.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): accept momentum strategy"
```

---

### Task 4: Frontend strategy dropdowns

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/history.html`

**Interfaces:**
- Consumes: the string key `"momentum"` from Task 2 (must match exactly as `<option value="...">`, or selecting the new label would submit a strategy name the backend/analytics service doesn't recognize).
- Produces: nothing new for later tasks — this is the final task in the plan.

- [ ] **Step 1: Update `backtest.html`'s strategy dropdown**

In `frontend/backtest.html`, change:

```html
      <option value="ma_crossover">MA Crossover</option>
      <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
      <option value="breakout">Breakout (Donchian)</option>
      <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
      <option value="method_714">714 Method</option>
```

to:

```html
      <option value="ma_crossover">MA Crossover</option>
      <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
      <option value="breakout">Breakout (Donchian)</option>
      <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
      <option value="momentum">Momentum</option>
      <option value="method_714">714 Method</option>
```

- [ ] **Step 2: Update `history.html`'s strategy filter dropdown**

In `frontend/history.html`, change:

```html
          <option value="">All</option>
          <option value="ma_crossover">MA Crossover</option>
          <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
          <option value="breakout">Breakout (Donchian)</option>
          <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
          <option value="method_714">714 Method</option>
          <option value="custom">Custom Strategy</option>
```

to:

```html
          <option value="">All</option>
          <option value="ma_crossover">MA Crossover</option>
          <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
          <option value="breakout">Breakout (Donchian)</option>
          <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
          <option value="momentum">Momentum</option>
          <option value="method_714">714 Method</option>
          <option value="custom">Custom Strategy</option>
```

- [ ] **Step 3: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/backtest.html`, select "Momentum" from the Strategy dropdown, fill in a symbol/date range that covers at least a year of daily bars (e.g. `AAPL`, equity, `2022-01-01` to `2023-06-01` — the default `lookback=252` needs it), click "Run backtest", and confirm it renders a result with no console errors.
3. Open `http://localhost:3000/history.html` and confirm "Momentum" appears as an option in the strategy filter dropdown.
4. Stop the dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/backtest.html frontend/history.html
git commit -m "feat(frontend): add momentum to strategy dropdowns"
```
