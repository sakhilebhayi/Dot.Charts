# ML-Based Signal (Explainable) — Design

**Roadmap:** sub-project 3 of 4 in the "quant-repo-derived strategy
expansion" initiative (see `2026-08-10-momentum-strategy-design.md` for the
initiative overview).

**Provenance:** approach follows Machine-Learning-for-Trading's core
supervised-learning pattern (engineered features → tree-based classifier →
walk-forward evaluation), reimplemented locally with `scikit-learn` — a
narrowly-scoped, well-understood new dependency, not the reference repo's
code itself.

## Problem

Every existing strategy is a fixed, human-specified rule (crossover
threshold, band touch, breakout level). None of them are estimated from
data. An ML-based strategy is a genuinely different category — but the
platform's disclosure/loss-honesty rules require every signal to be
explainable and confidence-banded, which rules out black-box approaches.

## Design Decision: Train Inline, Walk-Forward, No Persisted Model Store

The platform has no model-training infrastructure today (no model
registry, no scheduled retraining job, no artifact storage). Building that
is a separate, much larger infra project. Instead: **train the model
inside the single backtest request itself**, the same way every other
strategy computes its indicators inline from the fetched OHLCV — no new
infrastructure, no new operational surface (nothing to version, expire, or
go stale between requests).

This means every backtest run retrains from scratch — acceptable because
(a) gradient-boosted trees on a few hundred/thousand daily bars train in
low single-digit seconds, well within the existing synchronous backtest
request's latency budget, and (b) it sidesteps the entire "is this model
still valid" staleness question a persisted model would raise.

## Strategy Definition

```python
DEFAULT_PARAMS = {
    "train_window": 500,     # bars used per walk-forward training slice
    "retrain_every": 20,     # retrain cadence, in bars
    "n_estimators": 100,
    "max_depth": 3,          # shallow trees — explainability over raw accuracy
    "min_confidence": 0.55,  # predicted-probability floor to act on
}
```

**Features** (all already computable via `pandas_ta`, already a
dependency — no new feature-engineering dependency):
`rsi_14`, `macd_hist`, `atr_14_pct` (ATR normalized by close), `return_1d`,
`return_5d`, `return_10d`, `close_vs_sma_20_pct`. Seven features, each
individually interpretable — chosen for explainability, not for squeezing
out maximum accuracy.

**Target**: `1` if next-bar close > current close, else `0` (binary
direction classification — not a return-magnitude regression, keeping the
"confidence = predicted probability" framing simple and honest).

**Walk-forward evaluation** (the correctness-critical part): starting once
`train_window` bars are available, train on the trailing `train_window`
bars, predict forward `retrain_every` bars, then slide the training window
forward and retrain. At no point does a training slice include bars at or
after the bar being predicted — this is the one place a naive
implementation would leak future data into training (fitting once on the
whole dataset, then "predicting" over bars the model already saw). This is
called out explicitly because it's the primary review/test focus for this
sub-project.

```python
def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Returns (entries, exits, confidence) — confidence is the model's
    predicted probability at each entry bar, threaded through to the
    disclosure object."""
    ...

def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits, _confidence = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

Entry fires when the walk-forward model's predicted probability of
next-bar-up exceeds `min_confidence`; exit fires when it drops back below
0.5 (no longer predicting up). Long-only, matching the rest of the roster.

## Explainability & Disclosure

This is the strategy where the platform's existing "never a signal without
disclosure" rule needs a genuinely new field, not just the standard
confidence band:

- **`feature_importances`**: the trained model's `feature_importances_`
  (from `GradientBoostingClassifier`/`RandomForestClassifier`, both
  natively expose this) — top 3 features by importance, surfaced in the
  disclosure object so a user sees *what the model is responding to*
  (e.g. "RSI(14), MACD histogram, 5-day return"), not just a bare
  probability number.
- **`model_confidence`**: the predicted probability at the entry bar,
  distinct from the platform's existing strategy-level confidence band
  (which is about backtest-result reliability generally) — this is
  per-signal, model-specific.
- `DisclosureFormatter` needs a new branch (mirrors the `pairs_trading`
  spread caveat from the sibling spec): ML-signal disclosures state the
  model type, feature set, and walk-forward retraining cadence in the
  attribution text, so "this came from a model" is never implicit.

## Module Contract & Backend Changes

New file `analytics/strategies/ml_signal.py`. New dependency:
`scikit-learn` (`analytics/requirements.txt`).

Registry entry:

```python
"ml_signal": {
    "engine": "vectorbt",
    "module": ml_signal,
    "default_params": ml_signal.DEFAULT_PARAMS,
    "interval": "1d",
},
```

Fits the standard single-`df` module contract (unlike `pairs_trading`) —
no backend dispatch changes needed beyond the same four touchpoints every
prior strategy addition required:

- **`BacktestController::store`** validation rule extends to include
  `ml_signal`.
- **`DisclosureFormatter::STRATEGY_LABELS`**: `'ml_signal' => 'ML Signal (Explainable)'`,
  plus the attribution-text branch described above.
- **`frontend/backtest.html`** / **`frontend/history.html`**: strategy
  `<select>` gains the option in both.

`DEFAULT_PARAMS["train_window"] = 500` means, like momentum's 252-bar
lookback, this strategy needs a substantial history before it can produce
its first signal — same UI-description note applies.

## Testing

- **`analytics/tests/test_ml_signal.py`**:
  - A synthetic series with an obvious, learnable pattern (e.g. price
    deterministically follows a lagged feature) asserts the model
    achieves better-than-chance walk-forward accuracy — a sanity check
    that the pipeline actually learns something, not a strict accuracy
    threshold (real market data won't hit synthetic-fixture accuracy).
  - **Leakage regression test** (the most important test in this
    sub-project): asserts that shuffling the bars *after* the last
    training cutoff of a given walk-forward slice does not change that
    slice's predictions — proves the model genuinely can't see forward.
  - Asserts `feature_importances` and `model_confidence` are present and
    well-formed (sums to ~1.0 / in `[0, 1]`) in the returned signal data.
- **`analytics/tests/test_backtest_endpoint.py`**: smoke test through
  `/backtest`, confirming valid `BacktestResult` shape including the new
  disclosure fields.
- **`backend/tests/Feature/BacktestControllerTest.php`**: case asserting
  `ml_signal` is accepted by `store`'s validation.
- **`backend/tests/Feature/DisclosureFormatterTest.php`** (or equivalent):
  asserts the ML-specific attribution branch renders feature importances
  and model description, not just the generic strategy label.

## Open Questions

| Question | Owner → Approver |
|---|---|
| `scikit-learn` as a new dependency — confirm acceptable. Mature, no unusual transitive weight, widely used alongside `pandas`/`numpy` already in the stack. | You → — |
| Per-request retraining cost: confirm the synchronous `/backtest` request latency budget (needs checking against `main.py`'s existing timeout handling) can absorb walk-forward training over a multi-year daily-bar window without needing to move to an async/queued job. If not, this sub-project may need a "confirm before running" UX step rather than blocking synchronously — flagged here rather than assumed. | You → — |
