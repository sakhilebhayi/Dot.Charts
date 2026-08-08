# Strategy Builder — F1: Rule Model + Execution Engine — Design

**Subsystem:** F (from the Dot.Charts gap-closure audit), sub-project 1 of 4

## Problem

The platform offers 5 fixed strategy presets (MA Crossover, RSI Mean-Reversion,
Breakout, Bollinger Mean-Reversion, 714 Method) with parameter tuning, but no
way for a user to compose their own strategy logic. `wiki.md` describes a
long-term "visual strategy builder" vision at the ecosystem level.

## Scope Decomposition

The full visual (drag-and-drop canvas) strategy builder is too large for one
implementation plan — it decomposes into 4 independent sub-projects, each with
its own spec → plan → build cycle:

1. **F1 (this spec)** — a JSON rule schema for conditions, combined with
   AND/OR into entry/exit rules, plus a generic `custom` strategy that
   interprets that JSON and runs it through the existing vectorbt pipeline.
   Backend only, no UI. Foundational — everything else depends on it.
2. **F2** — persistence: a table + CRUD endpoints so users can save/load/
   list/delete strategies they've built, building on F1's schema.
3. **F3** — the visual canvas itself: a drag-and-drop node editor (indicator
   blocks, connectors) that produces F1's JSON rule structure as its output.
4. **F4** — integration: wire saved custom strategies into `backtest.html`'s
   strategy dropdown, History, etc., alongside the 5 existing presets.

This spec covers **F1 only**. Its success criterion is that `custom` works
correctly through a direct API call, proving the rule model and execution
engine are solid before any UI is built on top of them — `backtest.html`'s
dropdown does **not** get a `custom` option in this slice (see Scope
Boundary below).

## Rule JSON Schema

```json
{
  "entry": {
    "combinator": "all",
    "conditions": [
      {"left": {"indicator": "ema", "length": 20}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 50}},
      {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 70}}
    ]
  },
  "exit": {
    "combinator": "any",
    "conditions": [
      {"left": {"indicator": "close"}, "comparator": "crosses_below", "right": {"indicator": "bb_lower", "length": 20, "std": 2.0}}
    ]
  }
}
```

- Each condition's `left`/`right` operand is either `{"indicator": "<name>", ...params}`
  or `{"value": <number>}` for a static threshold.
- Supported indicators: `close`, `open`, `high`, `low`, `volume` (raw
  columns, no params), `ema`/`sma`/`rsi` (require `length`), `atr` (requires
  `length`), `bb_upper`/`bb_mid`/`bb_lower` (require `length` and `std`).
- Supported comparators: `crosses_above`, `crosses_below` (momentary
  crossover events, via the same `.shift(1)` pattern every existing
  strategy already uses for crossover detection), `greater_than`,
  `less_than` (static elementwise comparison).
- `combinator` is `"all"` (AND, every condition must hold) or `"any"` (OR,
  at least one condition must hold), applied across the whole
  `conditions` list — flat, not nested. Nested AND/OR groups are
  explicitly deferred to a later enhancement once this flat model proves
  out; it's YAGNI for F1 and every one of the 5 existing presets is
  expressible as a flat rule set.

## Execution Engine

A new `analytics/strategies/custom.py` follows the exact same module
contract as every existing vectorbt strategy:

```python
DEFAULT_PARAMS = {}  # custom's params ARE the rule JSON; no scalar defaults apply

def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    ...

def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

`generate_signals` validates `params` against the schema above (raising a
new `InvalidStrategyParamsError` on any malformed rule — unknown
indicator/comparator/combinator, missing required field), resolves each
condition's `left`/`right` operand to a `pd.Series` (raw column lookup, or
computed via `pandas_ta` for the indicator types), applies the condition's
comparator, and combines the resulting boolean Series across the
condition list with `&` (`"all"`) or `|` (`"any"`).

**No new endpoint is needed.** `/backtest` already accepts an arbitrary
`params: dict`, merged over the strategy's `default_params` — `custom`'s
`params` simply *is* the rule JSON, following the same mechanism every
other strategy's tunable parameters already use.

`InvalidStrategyParamsError` is caught in `main.py` alongside the existing
`DataFetchError` handling and returned as an HTTP 422, consistent with the
endpoint's existing error contract (unknown strategy, bad asset class,
data-fetch failure are all already 422s).

## Scope Boundary

`STRATEGY_REGISTRY["custom"]`, `schemas.py`'s `StrategyName` Literal, and
Laravel's validation allow-list / `DisclosureFormatter` label all include
`custom` — the backend contract is complete end-to-end and testable via a
direct API call. `frontend/backtest.html`'s strategy dropdown does **not**
get a `custom` option: the frontend currently always sends `params: {}`
for every strategy (there is no per-strategy parameter UI at all yet), so
selecting `custom` from that dropdown today would immediately fail
validation with no way for a user to supply a real rule set. `custom`
becomes reachable through the actual product UI only once F3 (the canvas,
which produces this rule JSON) and F4 (integration) exist.

## Testing

- **`analytics/tests/test_custom_strategy.py`**: unit tests on
  `generate_signals` — one fixture per comparator (a crossover case and a
  threshold case) proving correct signal timing; one combinator test
  proving `"all"` requires every condition while `"any"` needs just one;
  one validation-error test per malformed-rule case (unknown indicator,
  unknown comparator, unknown combinator, missing required field on an
  indicator operand).
- **`analytics/tests/test_backtest_endpoint.py`**: a `/backtest` smoke
  test with `strategy: "custom"` and a real rule payload, confirming
  end-to-end registry/schema wiring; a 422 test for a malformed rule
  payload.
- **`backend/tests/Feature/BacktestControllerTest.php`**: one test
  confirming `custom` is accepted by Laravel's validation and a rule
  payload passes through to the analytics service correctly (mirrors the
  existing strategy-acceptance test pattern from the strategy-expansion
  slice).
