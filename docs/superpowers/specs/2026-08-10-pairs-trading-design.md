# Pairs Trading / Statistical Arbitrage — Design

**Roadmap:** sub-project 2 of 4 in the "quant-repo-derived strategy
expansion" initiative (see `2026-08-10-momentum-strategy-design.md` for the
initiative overview). This is the first sub-project that changes the
platform's single-symbol assumption — read the Open Questions section
before implementing.

**Provenance:** cointegration-based pairs trading is a standard
statistical-arbitrage technique documented across awesome-quant's curated
resources and Machine-Learning-for-Trading. Reimplemented locally with
`statsmodels` (a new, narrowly-scoped dependency for the Engle-Granger
test) — not installed as a strategy library.

## Problem

Every existing strategy trades one instrument against its own price
history. Pairs trading is a genuinely different category — a signal
derived from the *relationship* between two instruments — and the current
backtest request shape (`symbol`, `strategy`, `params`) has no way to
express a second instrument.

## Design Decision: Trade the Spread as a Synthetic Instrument

The vectorbt engine (`engines/vectorbt_engine.py`) and the metrics pipeline
(`metrics.py`) are both built around `vbt.Portfolio.from_signals(price_series, entries, exits, ...)`
for a *single* price series. True pairs trading needs simultaneous long one
leg / short the other, with its own two-leg P&L accounting — a
significantly larger engine change.

Instead: compute the cointegrated spread between the two symbols, treat
that spread as if it were a single instrument's price, and feed it through
the existing single-series pipeline unchanged. This means:

- `Portfolio.from_signals(spread, entries, exits, ...)` reuses every
  existing metric (`compute_metrics_from_portfolio` in `metrics.py`) with
  zero changes to that file.
- **Trade-off, stated explicitly for the disclosure text**: reported
  Sharpe/drawdown/PnL describe the spread's behavior, not two separately
  filled, separately financed legs with their own borrow costs, margin,
  and slippage. This is a real backtest of the *signal*, not
  broker-accurate execution accounting. The strategy's disclosure object
  must say so (see Disclosure section below) — this is the same
  loss-honesty/no-false-precision principle already enforced platform-wide
  (`DisclosureFormatter`, the platform's compliance-gate loss-honesty
  rule).

## Strategy Definition

```python
DEFAULT_PARAMS = {
    "lookback": 60,       # window for rolling hedge-ratio / z-score
    "entry_z": 2.0,
    "exit_z": 0.5,
    "stop_z": 4.0,         # hard stop if the spread keeps diverging
}
```

1. **Cointegration check** (`statsmodels.tsa.stattools.coint`): run once
   over the full backtest window before generating any signals. If the
   p-value is above a fixed threshold (0.05), the two symbols aren't
   cointegrated over this window — the strategy returns empty
   entries/exits rather than trading a spurious spread. This is a
   correctness gate, not a tunable param, matching this repo's existing
   pattern of hard-coding safety invariants rather than exposing them as
   footguns (cf. the loss-honesty fields being structurally non-omittable
   in `ObservationPackGenerator`).
2. **Hedge ratio**: rolling OLS of `price_a` on `price_b` over `lookback`
   bars (`statsmodels.OLS` or a rolling `numpy.polyfit`), refit each bar —
   avoids a single static ratio going stale over a long backtest.
3. **Spread & z-score**: `spread = price_a - hedge_ratio * price_b`;
   `z = (spread - spread.rolling(lookback).mean()) / spread.rolling(lookback).std()`.
4. **Signals**: entry when `z` crosses below `-entry_z` (long the spread,
   i.e. long A / short B — but see the synthetic-instrument note above,
   this manifests as a long-only signal on the synthetic `spread` series);
   exit when `z` crosses back above `-exit_z`, or a hard exit if `z`
   breaches `-stop_z` (divergence stop).

```python
def generate_signals(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Returns (entries, exits, spread) — spread is the synthetic price series
    the caller feeds to vbt.Portfolio.from_signals."""
    ...

def run(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits, spread = generate_signals(df_a, df_b, params)
    return vbt.Portfolio.from_signals(spread, entries, exits, freq="1D", init_cash=10_000)
```

Note the changed function signature (`df_a, df_b` instead of `df`) — this
is the one strategy in the registry that doesn't fit the existing
single-`df` module contract, which is why it needs its own dispatch path
rather than a plain registry entry (see Backend Changes below).

## Backend Changes

- **`BacktestController::store`**: gains an optional `symbol_b` field,
  required if and only if `strategy === 'pairs_trading'` (new custom
  validation rule, e.g. `Rule::requiredIf(fn () => $request->input('strategy') === 'pairs_trading')`).
- **Analytics `/backtest` endpoint** (`main.py`): when
  `strategy == "pairs_trading"`, fetches both symbols' OHLCV instead of
  one, and calls `pairs_trading.run(df_a, df_b, params)` instead of the
  generic single-`df` dispatch every other vectorbt strategy uses. This is
  a small branch in the endpoint, not a rewrite of the dispatch mechanism.
- **`STRATEGY_REGISTRY`** gains a `"pairs_trading"` entry with an added
  `"requires_symbol_b": True` flag the endpoint checks before dispatching.
- **`DisclosureFormatter::STRATEGY_LABELS`**: `'pairs_trading' => 'Pairs Trading (Stat-Arb)'`.
  Its attribution text generation also needs a pairs-specific branch to
  state the synthetic-spread caveat from the Design Decision section
  above — every other strategy's disclosure text assumes a single
  instrument.
- **`frontend/backtest.html`**: strategy `<select>` gains the option; a
  second symbol input field appears (conditionally, via existing
  vanilla-JS show/hide pattern already used for other strategy-specific
  param fields, e.g. `custom`'s rule builder) when `pairs_trading` is
  selected.
- **`frontend/history.html`**: strategy filter gains the option; the
  history table's per-row strategy detail needs to show both symbols for
  pairs runs (currently assumes one `symbol` column).

## Testing

- **`analytics/tests/test_pairs_trading.py`**: two synthetic price series
  engineered to be genuinely cointegrated (e.g. `b = a + stationary_noise`)
  with a known divergence-then-reversion bar, asserting entry fires at the
  divergence and exit at reversion; a second fixture with two unrelated
  random-walk series asserts the cointegration gate returns empty
  entries/exits (no spurious trades).
- **`analytics/tests/test_backtest_endpoint.py`**: smoke test through
  `/backtest` with `symbol_b` present, confirming the two-symbol dispatch
  path produces a valid `BacktestResult`; a second test asserts a
  `pairs_trading` request *without* `symbol_b` returns a clear 4xx rather
  than a Python-side crash.
- **`backend/tests/Feature/BacktestControllerTest.php`**: cases for (a)
  `pairs_trading` + `symbol_b` present → accepted, (b) `pairs_trading`
  without `symbol_b` → 422, (c) non-pairs strategy with `symbol_b` present
  is simply ignored (not an error) — keeps the validation rule
  permissive in the direction that can't cause harm.

## Open Questions

| Question | Owner → Approver |
|---|---|
| Confirm the synthetic-spread simplification (Design Decision above) is acceptable for a first version, vs. holding for a true two-leg engine. Recommendation: ship the synthetic version now — it delivers the actual tradeable signal (entries/exits/z-score) which is the part users act on, and the disclosure caveat keeps it honest about what the reported metrics do and don't represent. | You → — |
| `statsmodels` is a new dependency (`analytics/requirements.txt`) — confirm it's acceptable alongside the existing `pandas-ta`/`vectorbt`/`backtrader` stack. It's a mature, widely-used stats library with no unusual transitive weight. | You → — |
