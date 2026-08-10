# Strategy Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `breakout` (Donchian channel) and `bollinger_mean_reversion` as two new vectorbt-based strategy presets, wired all the way through the analytics service, Laravel validation/attribution, and both frontend strategy dropdowns.

**Architecture:** Two new strategy modules under `analytics/strategies/`, each matching the existing `DEFAULT_PARAMS` / `generate_signals(df, params)` / `run(df, params)` contract exactly, registered in `STRATEGY_REGISTRY`. Every other place that already hand-enumerates strategy names (Laravel's `store` validation, `DisclosureFormatter`'s labels, both frontend `<select>`s) gets the same two names added.

**Tech Stack:** Python `pandas`/`pandas_ta`/`vectorbt` (all existing dependencies, no new ones), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- Both new strategies are long-only, matching every existing strategy (per spec's Strategy Definitions section).
- Breakout's rolling channels must be `.shift(1)`'d before comparison — using the unshifted channel is a lookahead bug where the breakout bar's own high/low is baked into the threshold it's supposedly breaking (per spec's Breakout section).
- Bollinger exit is at the middle band (SMA), not the upper band (per spec's Bollinger Mean-Reversion section — an explicit, deliberate choice, not the only valid interpretation).
- No changes to `main.py`'s `/backtest` endpoint or `engines/vectorbt_engine.py` — both are already strategy-agnostic (per spec's Module Contract section).

---

### Task 1: Breakout strategy (Donchian channel)

**Files:**
- Create: `analytics/strategies/breakout.py`
- Test: `analytics/tests/test_breakout.py`

**Interfaces:**
- Consumes: nothing new (uses `pandas`, `vectorbt` only, same as `ma_crossover.py`).
- Produces: `DEFAULT_PARAMS = {"entry_lookback": 20, "exit_lookback": 10}`, `generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]`, `run(df: pd.DataFrame, params: dict) -> vbt.Portfolio` — the same three names Task 3 (registry) relies on.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_breakout.py
import pandas as pd
from strategies.breakout import generate_signals, DEFAULT_PARAMS


def _breakout_price_series() -> pd.DataFrame:
    # 25 flat bars (establishes a stable 20-bar Donchian channel), then a
    # sharp one-bar spike above the prior channel high on bar 25, then
    # flat again -- deterministic single breakout, no mocks needed.
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    flat_before = [100.0] * 25
    spike = [110.0]
    flat_after = [100.5] * 14
    close = pd.Series(flat_before + spike + flat_after, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_channel_breakout():
    df = _breakout_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.iloc[25], "expected entry on the spike bar breaking the prior 20-bar high"
    assert not entries.iloc[:25].any(), "no entry before the channel is established or before the spike"
    assert entries.sum() == 1


def test_generate_signals_fires_exit_on_channel_breakdown():
    # Mirror fixture: flat, then a sharp one-bar drop below the prior
    # 10-bar low, then flat again.
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    flat_before = [100.0] * 25
    drop = [90.0]
    flat_after = [99.5] * 14
    close = pd.Series(flat_before + drop + flat_after, index=idx)
    high = close + 0.5
    low = close - 0.5
    df = pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert exits.iloc[25], "expected exit on the drop bar breaking the prior 10-bar low"
    assert not exits.iloc[:25].any()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_breakout.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.breakout'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/strategies/breakout.py
import pandas as pd
import vectorbt as vbt

DEFAULT_PARAMS = {"entry_lookback": 20, "exit_lookback": 10}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    entry_lookback = params.get("entry_lookback", DEFAULT_PARAMS["entry_lookback"])
    exit_lookback = params.get("exit_lookback", DEFAULT_PARAMS["exit_lookback"])

    # Shifted by 1 so the breakout bar's own high/low never counts toward
    # the very channel it's breaking -- an unshifted channel would make
    # entries/exits impossible, since a bar's high can never exceed a
    # rolling max that includes itself.
    upper_channel = df["high"].rolling(entry_lookback).max().shift(1)
    lower_channel = df["low"].rolling(exit_lookback).min().shift(1)

    entries = df["close"] > upper_channel
    exits = df["close"] < lower_channel

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_breakout.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/breakout.py analytics/tests/test_breakout.py
git commit -m "feat(strategies): add Donchian channel breakout strategy"
```

---

### Task 2: Bollinger Band mean-reversion strategy

**Files:**
- Create: `analytics/strategies/bollinger_mean_reversion.py`
- Test: `analytics/tests/test_bollinger_mean_reversion.py`

**Interfaces:**
- Consumes: nothing new (uses `pandas`, `pandas_ta`, `vectorbt`, same as `rsi_mean_reversion.py`).
- Produces: `DEFAULT_PARAMS = {"length": 20, "std": 2.0}`, `generate_signals(df, params) -> tuple[pd.Series, pd.Series]`, `run(df, params) -> vbt.Portfolio` — same three names Task 3 (registry) relies on.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_bollinger_mean_reversion.py
import pandas as pd
from strategies.bollinger_mean_reversion import generate_signals, DEFAULT_PARAMS


def _mean_reverting_price_series() -> pd.DataFrame:
    # 25 bars of small, stable noise around 100 (establishes tight bands),
    # then one sharp dip well below the lower band, then a recovery back
    # above 100 (crosses back above the middle band/SMA).
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    stable = [100.0, 100.2, 99.8, 100.1, 99.9] * 5  # 25 bars, tight range
    dip = [90.0]
    recovery = [100.0] * 14
    close = pd.Series(stable + dip + recovery, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_lower_band_cross():
    df = _mean_reverting_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.iloc[25], "expected entry on the dip bar crossing below the lower band"
    assert not entries.iloc[:25].any(), "no entry during the stable, in-band section"


def test_generate_signals_fires_exit_on_middle_band_cross():
    df = _mean_reverting_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    # Some bar after the dip, once the SMA has caught up enough for close
    # to cross back above it, must fire an exit.
    assert exits.iloc[26:].any(), "expected an exit once price recovers back above the middle band"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_bollinger_mean_reversion.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.bollinger_mean_reversion'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/strategies/bollinger_mean_reversion.py
import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"length": 20, "std": 2.0}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    length = params.get("length", DEFAULT_PARAMS["length"])
    std = params.get("std", DEFAULT_PARAMS["std"])

    # Selected positionally rather than by exact column name -- pandas_ta's
    # bbands() column-name suffix format (e.g. "BBL_20_2.0_2.0" on the
    # installed version, verified via `ta.bbands(...).columns.tolist()`)
    # is not consistent across releases, but the column order (lower,
    # middle, upper, bandwidth, percent) is stable and documented.
    bands = ta.bbands(df["close"], length=length, std=std)
    lower = bands.iloc[:, 0]
    middle = bands.iloc[:, 1]

    entries = (df["close"] < lower) & (df["close"].shift(1) >= lower.shift(1))
    exits = (df["close"] > middle) & (df["close"].shift(1) <= middle.shift(1))

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_bollinger_mean_reversion.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/bollinger_mean_reversion.py analytics/tests/test_bollinger_mean_reversion.py
git commit -m "feat(strategies): add Bollinger Band mean-reversion strategy"
```

---

### Task 3: Register both strategies + `/backtest` endpoint smoke tests

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `breakout.DEFAULT_PARAMS`/`breakout` module from Task 1; `bollinger_mean_reversion.DEFAULT_PARAMS`/`bollinger_mean_reversion` module from Task 2.
- Produces: `STRATEGY_REGISTRY["breakout"]` and `STRATEGY_REGISTRY["bollinger_mean_reversion"]` entries — later tasks (Laravel, frontend) rely on these exact string keys.

- [ ] **Step 1: Write the failing test**

Append to `analytics/tests/test_backtest_endpoint.py`:

```python
def test_backtest_breakout_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "breakout",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "breakout"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_bollinger_mean_reversion_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "bollinger_mean_reversion",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "bollinger_mean_reversion"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k "breakout or bollinger"`
Expected: FAIL with 422 responses (`assert 422 == 200`), since `STRATEGY_REGISTRY` doesn't recognize either name yet.

- [ ] **Step 3: Write the implementation**

Replace the full contents of `analytics/strategies/__init__.py`:

```python
from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion
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

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v`
Expected: PASS (all tests in the file, including the 2 new ones)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/__init__.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(strategies): register breakout and bollinger_mean_reversion in STRATEGY_REGISTRY"
```

---

### Task 4: Laravel validation + attribution labels

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: the two string keys `"breakout"` and `"bollinger_mean_reversion"` from Task 3 (must match exactly, or a valid Python-service strategy would still 422 at the Laravel layer).
- Produces: nothing new for later tasks — this task's effect is entirely in validation/label behavior.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/BacktestControllerTest.php`, inside the `BacktestControllerTest` class (same file already has `use RefreshDatabase;` and `use Illuminate\Support\Facades\Http;` — no new imports needed):

```php
    public function test_store_accepts_breakout_and_bollinger_mean_reversion_strategies(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'breakout',
                'params' => ['entry_lookback' => 20, 'exit_lookback' => 10],
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

        $breakoutResponse = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'breakout',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);
        $breakoutResponse->assertOk();
        $breakoutResponse->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'Breakout (Donchian)');
        });

        $bollingerResponse = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'bollinger_mean_reversion',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);
        $bollingerResponse->assertOk();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_store_accepts_breakout_and_bollinger_mean_reversion_strategies`
Expected: FAIL — the `strategy` field's `in:...` validation rule rejects `breakout` with a 422, so `$breakoutResponse->assertOk()` fails.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, change:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714',
```

to:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion',
```

In `backend/app/Services/DisclosureFormatter.php`, change:

```php
    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
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
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=test_store_accepts_breakout_and_bollinger_mean_reversion_strategies`
Expected: PASS

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/app/Services/DisclosureFormatter.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): accept breakout and bollinger_mean_reversion strategies"
```

---

### Task 5: Frontend strategy dropdowns

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/history.html`

**Interfaces:**
- Consumes: the two string keys `"breakout"` and `"bollinger_mean_reversion"` from Task 3 (must match exactly as `<option value="...">`, or selecting the new label would submit a strategy name the backend/analytics service doesn't recognize).
- Produces: nothing new for later tasks — this is the final task in the plan.

- [ ] **Step 1: Update `backtest.html`'s strategy dropdown**

In `frontend/backtest.html`, change:

```html
    <option value="ma_crossover">MA Crossover</option>
    <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
    <option value="method_714">714 Method</option>
```

to:

```html
    <option value="ma_crossover">MA Crossover</option>
    <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
    <option value="breakout">Breakout (Donchian)</option>
    <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
    <option value="method_714">714 Method</option>
```

- [ ] **Step 2: Update `history.html`'s strategy filter dropdown**

In `frontend/history.html`, apply the identical change to its strategy `<select>` (same three existing `<option>` lines, same insertion).

- [ ] **Step 3: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/backtest.html`, select "Breakout (Donchian)" from the Strategy dropdown, fill in a symbol/date range (e.g. `AAPL`, equity, `2023-01-01` to `2023-06-01`), click "Run backtest", and confirm it renders a result with no console errors.
3. Repeat for "Bollinger Mean-Reversion".
4. Open `http://localhost:3000/history.html` and confirm both new strategies appear as options in the filter dropdown.
5. Stop the dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/backtest.html frontend/history.html
git commit -m "feat(frontend): add breakout and bollinger_mean_reversion to strategy dropdowns"
```
