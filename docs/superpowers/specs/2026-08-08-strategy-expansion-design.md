# Strategy Expansion — Design

**Subsystem:** E (from the Dot.Charts gap-closure audit)

## Problem

Dot.Charts currently offers three backtestable strategies: MA Crossover,
RSI Mean-Reversion (both `vectorbt`-based), and 714 Method
(`backtrader`-based). The gap audit calls for expanding the vectorbt-based
roster with two more well-known, generic strategies: a breakout strategy
and a Bollinger Band mean-reversion strategy.

## Goal

Add `breakout` (Donchian channel) and `bollinger_mean_reversion` as new
strategy presets, following the exact same module contract, engine, and
integration pattern as the two existing vectorbt strategies — including
every touchpoint outside the analytics service that already has to know
about strategy names (Laravel's validation allow-list, its attribution
labels, and both frontend strategy dropdowns).

## Strategy Definitions

### Breakout (Donchian channel)

```python
DEFAULT_PARAMS = {"entry_lookback": 20, "exit_lookback": 10}
```

- `upper = df["high"].rolling(entry_lookback).max()` — entry fires when
  `close` crosses above `upper.shift(1)` (yesterday's channel top, not
  today's — using the unshifted channel would let the breakout bar's own
  high count toward the threshold it's supposedly breaking, which can
  never fire since the current bar's high can't exceed a max that
  includes itself).
- `lower = df["low"].rolling(exit_lookback).min()` — exit fires when
  `close` crosses below `lower.shift(1)`, for the same shifted-lookahead
  reason. `exit_lookback` defaults shorter than `entry_lookback` (10 vs
  20) so exits react faster than entries, the standard turtle-trading
  asymmetry.

### Bollinger Mean-Reversion

```python
DEFAULT_PARAMS = {"length": 20, "std": 2.0}
```

- Bands computed via `pandas_ta.bbands(df["close"], length=length,
  std=std)`, giving lower/middle(SMA)/upper series.
- Entry fires when `close` crosses below the lower band (classic "buy the
  dip").
- Exit fires when `close` crosses back above the middle band (the SMA) —
  not the upper band — so the strategy takes profit at the mean rather
  than holding through the full round-trip to the opposite extreme.

Both strategies are long-only, matching the existing MA Crossover and RSI
Mean-Reversion strategies — no new short-selling capability is introduced.

## Module Contract

Each strategy is a new file under `analytics/strategies/`, matching the
existing two exactly:

```python
DEFAULT_PARAMS: dict = {...}

def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    ...
    return entries.fillna(False), exits.fillna(False)

def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

`analytics/strategies/__init__.py`'s `STRATEGY_REGISTRY` gains two entries:

```python
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
```

No changes to `main.py`'s `/backtest` endpoint or `engines/vectorbt_engine.py`
— both are already strategy-agnostic (they read `entry["module"]`/
`entry["default_params"]` from the registry generically).

## Integration Points Outside the Analytics Service

Every other place in the codebase that already enumerates strategy names
by hand needs the same two additions, or the new strategies would be
runnable directly against the Python service but unreachable/mislabeled
through the actual product:

- **`backend/app/Http/Controllers/BacktestController.php`**: the `store`
  validation rule `'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714'`
  extends to include `breakout,bollinger_mean_reversion`. Without this, a
  request naming either new strategy 422s at the Laravel layer before it
  ever reaches the analytics service — the same class of bug the
  commodities slice's asset-class allow-list had.
- **`backend/app/Services/DisclosureFormatter.php`**: `STRATEGY_LABELS`
  gains `'breakout' => 'Breakout (Donchian)'` and
  `'bollinger_mean_reversion' => 'Bollinger Mean-Reversion'`, so attribution
  text shows a readable label instead of falling back to the raw registry
  key.
- **`frontend/backtest.html`**: the strategy `<select>` gains
  `<option value="breakout">Breakout (Donchian)</option>` and
  `<option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>`.
- **`frontend/history.html`**: its strategy filter `<select>` gains the
  same two options, so saved runs using the new strategies can be filtered
  in the History view.

## Testing

- **`analytics/tests/test_breakout.py`** and
  **`analytics/tests/test_bollinger_mean_reversion.py`**: unit tests on
  `generate_signals`, following `test_ma_crossover.py`'s pattern — a small
  synthetic OHLCV fixture engineered to cross the relevant threshold on a
  known bar, asserting the entry/exit signal fires exactly there and
  nowhere else.
- **`analytics/tests/test_backtest_endpoint.py`**: one smoke test per new
  strategy through the `/backtest` endpoint, confirming the registry
  wiring produces a valid `BacktestResult` shape (mirrors the existing
  `test_backtest_ma_crossover_returns_metrics_and_trades` test).
- **`backend/tests/Feature/BacktestControllerTest.php`**: one new test
  case asserting both new strategy names are accepted by `store`
  (mirrors the existing "accepts commodity asset class" test).
