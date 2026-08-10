# Momentum / Trend-Following Strategy — Design

**Roadmap:** part of the "quant-repo-derived strategy expansion" initiative
(4 sub-projects: momentum, pairs trading, ML signal, options/vol — see the
sibling spec files dated 2026-08-10). This is sub-project 1 of 4, and the
only one with no open architectural questions — ship first.

**Provenance:** technique selection informed by Machine-Learning-for-Trading
(Jansen) and the awesome-quant curated list. No external code or library is
installed — the signal logic is reimplemented locally to match this
repo's existing strategy contract, same as `breakout` and
`bollinger_mean_reversion` were (see
`2026-08-08-strategy-expansion-design.md`).

## Problem

Dot.Charts' strategy roster (`ma_crossover`, `rsi_mean_reversion`,
`breakout`, `bollinger_mean_reversion`, `custom`, `method_714`) has no
momentum strategy — a foundational, well-studied category distinct from
the mean-reversion and crossover strategies already present.

## Goal

Add `momentum` as a new vectorbt-based strategy preset, following the exact
module contract every other vectorbt strategy already uses.

## Strategy Definition

```python
DEFAULT_PARAMS = {
    "lookback": 252,   # trading days of trailing return to measure
    "skip": 21,        # most-recent days excluded from the lookback
    "roc_window": 10,  # short window for the ROC confirmation leg
    "roc_threshold": 0.0,
}
```

Two signals combined, both standard time-series-momentum building blocks:

- **Trailing momentum filter**: `mom = df["close"].shift(skip) / df["close"].shift(lookback) - 1`
  — the classic 12-month-minus-most-recent-month momentum measure (default
  `lookback=252, skip=21` trading days ≈ 12 months skipping the most recent
  month, the standard formulation used to avoid short-term reversal
  contaminating the signal). Long-eligible when `mom > 0`.
- **ROC confirmation**: `roc = df["close"].pct_change(roc_window)`. Entry
  only fires when both `mom > 0` AND `roc` crosses above `roc_threshold` —
  the trailing filter says "in an uptrend," the ROC crossover says "and
  turning up right now," which avoids entering on every bar of a
  multi-month uptrend.
- Exit fires when `mom` crosses below 0 (the long-term trend filter itself
  breaks) — a trend-following exit, not a fixed target, consistent with
  the strategy's premise.

```python
def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    lookback = params.get("lookback", DEFAULT_PARAMS["lookback"])
    skip = params.get("skip", DEFAULT_PARAMS["skip"])
    roc_window = params.get("roc_window", DEFAULT_PARAMS["roc_window"])
    roc_threshold = params.get("roc_threshold", DEFAULT_PARAMS["roc_threshold"])

    mom = df["close"].shift(skip) / df["close"].shift(lookback) - 1
    roc = df["close"].pct_change(roc_window)

    trend_up = mom > 0
    roc_cross_up = (roc > roc_threshold) & (roc.shift(1) <= roc_threshold)

    entries = trend_up & roc_cross_up
    exits = (mom < 0) & (mom.shift(1) >= 0)

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

Long-only, matching every existing vectorbt strategy — no new
short-selling capability introduced.

`DEFAULT_PARAMS["lookback"] = 252` means the strategy needs at least a
year of daily bars before it can fire its first signal — worth surfacing
in the strategy's UI description so users don't file a "no trades
generated" bug against a 3-month backtest window.

## Module Contract

New file `analytics/strategies/momentum.py`, matching the existing
contract exactly (see code above). `analytics/strategies/__init__.py`'s
`STRATEGY_REGISTRY` gains:

```python
"momentum": {
    "engine": "vectorbt",
    "module": momentum,
    "default_params": momentum.DEFAULT_PARAMS,
    "interval": "1d",
},
```

No changes to `main.py`'s `/backtest` endpoint or
`engines/vectorbt_engine.py` — both are strategy-agnostic.

## Integration Points Outside the Analytics Service

Same four touchpoints the strategy-expansion precedent identified:

- **`backend/app/Http/Controllers/BacktestController.php`**: `store`'s
  validation rule extends to `...,breakout,bollinger_mean_reversion,momentum,custom`.
- **`backend/app/Services/DisclosureFormatter.php`**: `STRATEGY_LABELS`
  gains `'momentum' => 'Momentum'`.
- **`frontend/backtest.html`**: strategy `<select>` gains
  `<option value="momentum">Momentum</option>`.
- **`frontend/history.html`**: strategy filter `<select>` gains the same
  option.

## Testing

- **`analytics/tests/test_momentum.py`**: synthetic OHLCV fixture with a
  long enough flat period, then a sustained uptrend past the `lookback`
  window, asserting `entries` fires once trend + ROC align and not during
  the flat section (mirrors `test_ma_crossover.py`'s pattern). A second
  fixture reverses into a downtrend to assert `exits` fires when `mom`
  crosses below 0.
- **`analytics/tests/test_backtest_endpoint.py`**: one smoke test through
  `/backtest` confirming the registry wiring produces a valid
  `BacktestResult` shape.
- **`backend/tests/Feature/BacktestControllerTest.php`**: one new case
  asserting `momentum` is accepted by `store`'s validation.

## Open Questions

None — this sub-project has no architectural ambiguity; it follows an
already-proven pattern (`breakout`, `bollinger_mean_reversion`) exactly.
