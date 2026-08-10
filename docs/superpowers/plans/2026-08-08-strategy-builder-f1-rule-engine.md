# Strategy Builder F1: Rule Model + Execution Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `custom` strategy that interprets a JSON rule schema (conditions combined with AND/OR into entry/exit rules) and runs it through the existing vectorbt pipeline, complete end-to-end through the backend (analytics + Laravel), reachable via direct API calls — no frontend UI yet.

**Architecture:** `analytics/strategies/custom_rules.py` holds the reusable rule-evaluation primitives (operand resolution, comparators, combinators, validation). `analytics/strategies/custom.py` is a thin strategy module wrapping it, following the exact `DEFAULT_PARAMS`/`generate_signals`/`run` contract every existing vectorbt strategy already uses. `custom`'s `params` field IS the rule JSON — no new endpoint needed, since `/backtest` already accepts arbitrary `params: dict`.

**Tech Stack:** Python (`pandas`, `pandas_ta`, `vectorbt` — all existing dependencies), PHP/Laravel.

## Global Constraints

- Rule structure is flat: each of `entry`/`exit` is one combinator (`"all"` or `"any"`) applied across a flat `conditions` list — no nested AND/OR groups (per spec's Rule JSON Schema section).
- Supported indicators: `close`, `open`, `high`, `low`, `volume` (no params), `ema`/`sma`/`rsi`/`atr` (require `length`), `bb_upper`/`bb_mid`/`bb_lower` (require `length` and `std`). Supported comparators: `crosses_above`, `crosses_below`, `greater_than`, `less_than` (per spec's Rule JSON Schema section).
- `frontend/backtest.html`'s strategy dropdown does NOT get a `custom` option in this slice — explicitly out of scope (per spec's Scope Boundary section).
- Malformed rule params raise a new `InvalidStrategyParamsError`, returned as HTTP 422 from `/backtest`, consistent with the endpoint's existing error contract (per spec's Execution Engine section).

---

### Task 1: `custom_rules.py` — operand resolution, comparators, combinators, validation

**Files:**
- Create: `analytics/strategies/custom_rules.py`
- Test: `analytics/tests/test_custom_rules.py`

**Interfaces:**
- Consumes: nothing new (`pandas`, `pandas_ta`).
- Produces: `InvalidStrategyParamsError(Exception)`; `evaluate_rule(df: pd.DataFrame, rule: dict) -> pd.Series` (boolean Series) — Task 2's `custom.py` relies on this exact function name and signature.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_custom_rules.py
import pandas as pd
import pytest
from strategies.custom_rules import evaluate_rule, InvalidStrategyParamsError


def _trending_df() -> pd.DataFrame:
    # Flat for 60 bars, then a clean uptrend for 40 bars — same pattern
    # test_ma_crossover.py already uses, guarantees a deterministic EMA
    # crossover partway through.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_evaluate_rule_crosses_above_fires_on_crossover():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
        ],
    }

    result = evaluate_rule(df, rule)

    assert result.any()
    assert not result.iloc[:60].any(), "no crossover should fire during the flat section"


def test_evaluate_rule_less_than_fires_when_value_below_threshold():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},
        ],
    }

    result = evaluate_rule(df, rule)

    assert result.any()  # RSI is always < 200


def test_evaluate_rule_all_combinator_requires_every_condition():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1_000_000}},  # never true
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},  # always true
        ],
    }

    result = evaluate_rule(df, rule)

    assert not result.any()


def test_evaluate_rule_any_combinator_needs_just_one_condition():
    df = _trending_df()
    rule = {
        "combinator": "any",
        "conditions": [
            {"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1_000_000}},  # never true
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},  # always true
        ],
    }

    result = evaluate_rule(df, rule)

    assert result.all()


def test_evaluate_rule_rejects_unknown_indicator():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "made_up"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_unknown_comparator():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "close"}, "comparator": "made_up", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_unknown_combinator():
    df = _trending_df()
    rule = {"combinator": "made_up", "conditions": [{"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_indicator_missing_required_length():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "ema"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_empty_conditions_list():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": []}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_custom_rules.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.custom_rules'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/strategies/custom_rules.py
import pandas as pd
import pandas_ta as ta


class InvalidStrategyParamsError(Exception):
    pass


_RAW_COLUMNS = ("close", "open", "high", "low", "volume")
_LENGTH_ONLY_INDICATORS = ("ema", "sma", "rsi", "atr")
_BB_INDICATORS = {"bb_lower": 0, "bb_mid": 1, "bb_upper": 2}
_COMPARATORS = ("crosses_above", "crosses_below", "greater_than", "less_than")


def _require(operand: dict, key: str, indicator_name: str):
    if key not in operand:
        raise InvalidStrategyParamsError(f"Indicator '{indicator_name}' requires '{key}'")
    return operand[key]


def _resolve_operand(df: pd.DataFrame, operand: dict) -> pd.Series:
    if "value" in operand:
        return pd.Series(operand["value"], index=df.index)

    if "indicator" not in operand:
        raise InvalidStrategyParamsError(f"Operand must have 'indicator' or 'value': {operand}")

    name = operand["indicator"]

    if name in _RAW_COLUMNS:
        return df[name]

    if name in _LENGTH_ONLY_INDICATORS:
        length = _require(operand, "length", name)
        if name == "ema":
            return ta.ema(df["close"], length=length)
        if name == "sma":
            return ta.sma(df["close"], length=length)
        if name == "rsi":
            return ta.rsi(df["close"], length=length)
        return ta.atr(df["high"], df["low"], df["close"], length=length)

    if name in _BB_INDICATORS:
        length = _require(operand, "length", name)
        std = _require(operand, "std", name)
        # Selected positionally rather than by exact column name --
        # pandas_ta's bbands() column-name suffix format is not consistent
        # across releases, but the column order (lower, mid, upper,
        # bandwidth, percent) is stable (see bollinger_mean_reversion.py's
        # identical reasoning).
        bands = ta.bbands(df["close"], length=length, std=std)
        return bands.iloc[:, _BB_INDICATORS[name]]

    raise InvalidStrategyParamsError(f"Unknown indicator: {name}")


def _apply_comparator(left: pd.Series, right: pd.Series, comparator: str) -> pd.Series:
    if comparator == "crosses_above":
        return (left > right) & (left.shift(1) <= right.shift(1))
    if comparator == "crosses_below":
        return (left < right) & (left.shift(1) >= right.shift(1))
    if comparator == "greater_than":
        return left > right
    if comparator == "less_than":
        return left < right
    raise InvalidStrategyParamsError(f"Unknown comparator: {comparator}")


def evaluate_rule(df: pd.DataFrame, rule: dict) -> pd.Series:
    combinator = rule.get("combinator")
    if combinator not in ("all", "any"):
        raise InvalidStrategyParamsError(f"Unknown combinator: {combinator}")

    conditions = rule.get("conditions")
    if not conditions:
        raise InvalidStrategyParamsError("Rule must have at least one condition")

    signals = []
    for condition in conditions:
        comparator = condition.get("comparator")
        if comparator not in _COMPARATORS:
            raise InvalidStrategyParamsError(f"Unknown comparator: {comparator}")
        left = _resolve_operand(df, condition.get("left", {}))
        right = _resolve_operand(df, condition.get("right", {}))
        signals.append(_apply_comparator(left, right, comparator))

    combined = signals[0]
    for signal in signals[1:]:
        combined = (combined & signal) if combinator == "all" else (combined | signal)

    return combined.fillna(False)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_custom_rules.py -v`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/custom_rules.py analytics/tests/test_custom_rules.py
git commit -m "feat(strategy-builder): add rule evaluation engine (operands, comparators, combinators)"
```

---

### Task 2: `custom.py` — strategy module wrapper

**Files:**
- Create: `analytics/strategies/custom.py`
- Test: `analytics/tests/test_custom_strategy.py`

**Interfaces:**
- Consumes: `evaluate_rule(df, rule)` and `InvalidStrategyParamsError` from Task 1.
- Produces: `DEFAULT_PARAMS = {}`, `generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]`, `run(df: pd.DataFrame, params: dict) -> vbt.Portfolio` — Task 3's registry entry relies on these exact names, matching every other strategy module's contract.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_custom_strategy.py
import pandas as pd
import pytest
import vectorbt as vbt
from strategies.custom import generate_signals, run, DEFAULT_PARAMS
from strategies.custom_rules import InvalidStrategyParamsError


def _trending_df() -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _rule_params() -> dict:
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


def test_default_params_is_empty():
    assert DEFAULT_PARAMS == {}


def test_generate_signals_uses_entry_and_exit_rules():
    df = _trending_df()

    entries, exits = generate_signals(df, _rule_params())

    assert entries.any()


def test_generate_signals_raises_when_entry_rule_missing():
    df = _trending_df()
    params = {"exit": _rule_params()["exit"]}

    with pytest.raises(InvalidStrategyParamsError):
        generate_signals(df, params)


def test_generate_signals_raises_when_exit_rule_missing():
    df = _trending_df()
    params = {"entry": _rule_params()["entry"]}

    with pytest.raises(InvalidStrategyParamsError):
        generate_signals(df, params)


def test_run_returns_a_vectorbt_portfolio():
    df = _trending_df()

    portfolio = run(df, _rule_params())

    assert isinstance(portfolio, vbt.Portfolio)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_custom_strategy.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.custom'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/strategies/custom.py
import pandas as pd
import vectorbt as vbt

from .custom_rules import evaluate_rule, InvalidStrategyParamsError

DEFAULT_PARAMS = {}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    entry_rule = params.get("entry")
    exit_rule = params.get("exit")
    if not entry_rule:
        raise InvalidStrategyParamsError("params must include an 'entry' rule")
    if not exit_rule:
        raise InvalidStrategyParamsError("params must include an 'exit' rule")

    entries = evaluate_rule(df, entry_rule)
    exits = evaluate_rule(df, exit_rule)
    return entries, exits


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_custom_strategy.py -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/custom.py analytics/tests/test_custom_strategy.py
git commit -m "feat(strategy-builder): add custom strategy module wrapping the rule engine"
```

---

### Task 3: Register `custom` in the registry + schema + `/backtest` error handling

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Modify: `analytics/schemas.py`
- Modify: `analytics/main.py`
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `custom` module (`DEFAULT_PARAMS`, `generate_signals`, `run`) from Task 2; `InvalidStrategyParamsError` from Task 1.
- Produces: `STRATEGY_REGISTRY["custom"]`; `/backtest` returns HTTP 422 for `InvalidStrategyParamsError` — later tasks (Laravel wiring in Task 4) rely on this exact status code and the `"custom"` registry key.

- [ ] **Step 1: Write the failing test**

Append to `analytics/tests/test_backtest_endpoint.py`:

```python
def test_backtest_custom_strategy_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "custom",
            "params": {
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
            },
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "custom"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_custom_strategy_returns_422_on_invalid_rule(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "custom",
            "params": {"entry": {"combinator": "all", "conditions": []}, "exit": {"combinator": "all", "conditions": []}},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k custom`
Expected: FAIL — `test_backtest_custom_strategy_returns_metrics_and_trades` fails with a 422 (`Unknown strategy 'custom'`) since the registry doesn't have it yet; `test_backtest_custom_strategy_returns_422_on_invalid_rule` may already superficially "pass" with the wrong error (also 422 but for the wrong reason — unknown strategy, not invalid rule) — this is expected noise, resolved once Step 3 lands.

- [ ] **Step 3: Write the implementation**

Replace the full contents of `analytics/strategies/__init__.py`:

```python
from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion, custom
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

In `analytics/schemas.py`, change:

```python
StrategyName = Literal[
    "ma_crossover", "rsi_mean_reversion", "breakout", "bollinger_mean_reversion", "method_714",
]
```

to:

```python
StrategyName = Literal[
    "ma_crossover", "rsi_mean_reversion", "breakout", "bollinger_mean_reversion", "custom", "method_714",
]
```

In `analytics/main.py`, add the import:

```python
from strategies.custom_rules import InvalidStrategyParamsError
```

alongside the existing imports at the top of the file, then wrap the vectorbt engine call in `backtest()`. Change:

```python
    if entry["engine"] == "vectorbt":
        result = run_vectorbt(entry["module"], df, params)
```

to:

```python
    if entry["engine"] == "vectorbt":
        try:
            result = run_vectorbt(entry["module"], df, params)
        except InvalidStrategyParamsError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v`
Expected: PASS (all tests in the file, including the 2 new ones)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/__init__.py analytics/schemas.py analytics/main.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(strategy-builder): register custom strategy, handle InvalidStrategyParamsError as 422"
```

---

### Task 4: Laravel wiring

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `"custom"` as a valid `StrategyName` and HTTP 422 for invalid rule params, from Task 3.
- Produces: nothing new — final task in the plan.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/BacktestControllerTest.php`, near the existing `test_store_accepts_breakout_and_bollinger_mean_reversion_strategies` test:

```php
    public function test_store_accepts_custom_strategy_with_rule_params(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'custom',
                'params' => [
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
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 1.0,
                    'win_rate_pct' => 50.0,
                    'max_drawdown_pct' => -1.0,
                    'sharpe_ratio' => 0.3,
                    'trade_count' => 4,
                    'losing_trade_count' => 2,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'custom',
            'params' => [
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
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'Custom Strategy',
            $response->json('result.disclosure.attribution'),
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/backtest')
                && $request['strategy'] === 'custom'
                && $request['params']['entry']['combinator'] === 'all';
        });
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_store_accepts_custom_strategy_with_rule_params`
Expected: FAIL — the `strategy` field's `in:...` validation rule rejects `custom` with a 422, so `$response->assertOk()` fails.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, change:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion',
```

to:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,custom',
```

In `backend/app/Services/DisclosureFormatter.php`, change:

```php
    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
        'breakout' => 'Breakout (Donchian)',
        'bollinger_mean_reversion' => 'Bollinger Mean-Reversion',
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
        'custom' => 'Custom Strategy',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=test_store_accepts_custom_strategy_with_rule_params`
Expected: PASS

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Manual verification**

1. Start the analytics service: `cd analytics && .venv/bin/uvicorn main:app --port 8001`.
2. Run a real `custom` backtest via curl against `http://localhost:8001/backtest` with a real symbol (e.g. `AAPL`/`equity`) and the entry/exit rule payload from Task 3's test, confirming a valid `BacktestResult` with real `trade_count`.
3. Run the same request with a malformed rule (e.g. `"comparator": "not_real"`) and confirm HTTP 422 with a clear error message.
4. Stop the analytics service.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/app/Services/DisclosureFormatter.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(strategy-builder): accept custom strategy in Laravel validation and attribution"
```
