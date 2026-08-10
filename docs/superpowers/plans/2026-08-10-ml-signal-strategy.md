# ML Signal Strategy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `ml_signal` as a new vectorbt-based strategy preset — a walk-forward-trained, explainable gradient-boosted classifier over engineered indicator features — wired all the way through the analytics service, Laravel validation/attribution, and both frontend strategy dropdowns.

**Architecture:** A new strategy module (`analytics/strategies/ml_signal.py`) that fits the standard single-`df` module contract (unlike `pairs_trading`, no special dispatch needed). It trains inline, walk-forward, with no persisted model store: each backtest request retrains a fresh `GradientBoostingClassifier` on a trailing window, predicts forward, slides, and repeats. Explainability data (model type, top features by importance, retrain-block count) is surfaced by mutating the same `params` dict the caller already round-trips back through the API response — the same pattern `pairs_trading` established for `symbol_b`, reused here for a different kind of side-channel data, with zero `BacktestResult` schema changes.

**Tech Stack:** Python `pandas`/`pandas_ta`/`vectorbt` (existing) + `scikit-learn` (new declared dependency — was already present transitively via `vectorbt`'s own dependency chain, but the module imports it directly so it needs to be pinned explicitly), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- No persisted model store, no separate training service, no new infrastructure (per spec's Design Decision) — every backtest request retrains from scratch.
- Walk-forward evaluation must never let a training slice include a row at or after the bar being predicted (per spec's Strategy Definition, "Walk-forward evaluation" — the primary correctness risk called out in the spec, and the focus of Task 1's leakage regression test).
- Features are all computed via `pandas_ta`, already a dependency — no new feature-engineering dependency (per spec).
- **Refinement over the spec's stated 3-value return contract, locked in during planning:** the spec described surfacing `feature_importances`/`model_confidence` as new `BacktestResult`/disclosure fields, implying a schema change. This plan instead reuses the `pairs_trading` precedent — `generate_signals` mutates the caller's `params` dict in place (`params["model_diagnostics"] = {...}`), which `main.py` already threads unchanged from request through to the response's `params` field. `DisclosureFormatter` reads it from `$backtestResult['params']['model_diagnostics']`, same as it reads `pairs_trading`'s `symbol_b`. No `BacktestResult`/`TradeRecord` schema changes anywhere. `generate_signals`'s return signature stays the plain 3-tuple `(entries, exits, confidence)` the spec originally described, not the 4-tuple a naive implementation of "also return feature importances" would need.
- A training block is skipped (rather than fit on too little or single-class data) when the trailing window has fewer than 30 valid rows, or when the label column has only one class present (`MIN_TRAIN_ROWS = 30` in the module) — a **gap found during implementation, not in the spec**: a purely monotonic price fixture makes every label "up," which silently produces zero trained blocks. Real market data essentially never has this problem, but the guard is real: a training block that would fit a single-class target is fitting noise, not a genuine binary classifier.
- Disclosure states the model type, feature set, and retrain cadence explicitly (per spec's Explainability & Disclosure section) — this is the loss-honesty/no-implicit-model-output principle, same category as `pairs_trading`'s synthetic-spread caveat.

---

### Task 1: ML signal strategy module

**Files:**
- Create: `analytics/strategies/ml_signal.py`
- Test: `analytics/tests/test_ml_signal.py`
- Modify: `analytics/requirements.txt`

**Interfaces:**
- Consumes: `pandas_ta` (existing), `sklearn.ensemble.GradientBoostingClassifier` (new declared dependency).
- Produces: `DEFAULT_PARAMS = {"train_window": 500, "retrain_every": 20, "n_estimators": 100, "max_depth": 3, "min_confidence": 0.55}`, `FEATURE_NAMES` (list of 7 feature names), `generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]` (entries, exits, confidence — and a documented **side effect** of writing `params["model_diagnostics"]`), `run(df: pd.DataFrame, params: dict) -> vbt.Portfolio` — Task 2 (registry) relies on the exact three names every other vectorbt strategy module already exposes; Task 3 (Laravel) relies on the exact `model_diagnostics` dict shape (`model_type: str`, `top_features: list[{feature: str, importance: float}]`, `retrain_blocks: int`).

- [ ] **Step 1: Add the new dependency**

Append to `analytics/requirements.txt`:

```
scikit-learn>=1.4
```

(It may already resolve from the existing venv as a transitive dependency of `vectorbt` — declare it explicitly anyway since the module imports it directly, and a transitive resolution is not a guarantee.)

- [ ] **Step 2: Write the failing test**

```python
# analytics/tests/test_ml_signal.py
import numpy as np
import pandas as pd
from strategies.ml_signal import generate_signals

PARAMS = {"train_window": 100, "retrain_every": 20, "n_estimators": 100, "max_depth": 3, "min_confidence": 0.55}


def _autocorrelated_price_series() -> pd.DataFrame:
    # A learnable pattern: each day's move continues yesterday's direction
    # 85% of the time (a persistent-momentum random walk), so return_1d
    # should be a strong, genuinely predictive feature -- not a fixture
    # any model could fit by chance. Fixed seed (5) for reproducibility.
    n = 300
    idx = pd.date_range("2020-01-01", periods=n, freq="D")
    rng = np.random.default_rng(5)

    moves = np.zeros(n)
    moves[0] = 1.0
    for i in range(1, n):
        moves[i] = moves[i - 1] if rng.random() < 0.85 else -moves[i - 1]
    close = pd.Series(100 + np.cumsum(moves), index=idx)

    return pd.DataFrame({"open": close, "high": close + 0.3, "low": close - 0.3, "close": close, "volume": 1000})


def test_walk_forward_predictions_beat_chance_on_a_learnable_pattern():
    df = _autocorrelated_price_series()
    params = dict(PARAMS)

    entries, exits, confidence = generate_signals(df, params)

    target = (df["close"].shift(-1) > df["close"]).astype(int)
    mask = confidence.notna()
    assert mask.sum() > 0, "expected at least one trained walk-forward block to produce predictions"

    predicted_up = (confidence[mask] > 0.5).astype(int)
    accuracy = (predicted_up == target[mask]).mean()
    assert accuracy > 0.55, f"expected walk-forward accuracy clearly above chance on a learnable pattern, got {accuracy}"


def test_model_diagnostics_are_written_into_the_caller_params_dict():
    df = _autocorrelated_price_series()
    params = dict(PARAMS)

    generate_signals(df, params)

    diagnostics = params.get("model_diagnostics")
    assert diagnostics is not None, "expected generate_signals to mutate params with model_diagnostics"
    assert diagnostics["model_type"] == "GradientBoostingClassifier"
    assert diagnostics["retrain_blocks"] > 0
    assert 1 <= len(diagnostics["top_features"]) <= 3
    for entry in diagnostics["top_features"]:
        assert set(entry.keys()) == {"feature", "importance"}
        assert 0.0 <= entry["importance"] <= 1.0


def test_shuffling_future_bars_does_not_change_earlier_predictions():
    # The core leakage-regression check: a walk-forward block's predictions
    # must depend only on data up to that block's training cutoff. If a
    # naive implementation fit once on the whole series (or otherwise let
    # a training slice see rows at or after the bar being predicted),
    # shuffling bars far in the future would change earlier predictions --
    # it must not.
    df = _autocorrelated_price_series()
    params_a = dict(PARAMS)
    _entries_a, _exits_a, confidence_a = generate_signals(df, params_a)

    df_shuffled_tail = df.copy()
    rng = np.random.default_rng(99)
    tail = df_shuffled_tail["close"].iloc[150:].to_numpy().copy()
    rng.shuffle(tail)
    df_shuffled_tail.loc[df_shuffled_tail.index[150:], "close"] = tail
    df_shuffled_tail["open"] = df_shuffled_tail["close"]
    df_shuffled_tail["high"] = df_shuffled_tail["close"] + 0.3
    df_shuffled_tail["low"] = df_shuffled_tail["close"] - 0.3

    params_b = dict(PARAMS)
    _entries_b, _exits_b, confidence_b = generate_signals(df_shuffled_tail, params_b)

    # First walk-forward block trains on bars [0:100) and predicts [100:120)
    # -- entirely before the shuffled region (bar 150 onward).
    assert confidence_a.iloc[100:120].equals(confidence_b.iloc[100:120])
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_ml_signal.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'strategies.ml_signal'`

- [ ] **Step 4: Write the implementation**

```python
# analytics/strategies/ml_signal.py
import numpy as np
import pandas as pd
import pandas_ta as ta
import vectorbt as vbt
from sklearn.ensemble import GradientBoostingClassifier

DEFAULT_PARAMS = {
    "train_window": 500,
    "retrain_every": 20,
    "n_estimators": 100,
    "max_depth": 3,
    "min_confidence": 0.55,
}

FEATURE_NAMES = [
    "rsi_14",
    "macd_hist",
    "atr_14_pct",
    "return_1d",
    "return_5d",
    "return_10d",
    "close_vs_sma_20_pct",
]

# Below this many rows a GradientBoostingClassifier isn't meaningfully
# fittable and walk-forward evaluation is noise, not signal -- skip
# training that block rather than fit on too little data.
MIN_TRAIN_ROWS = 30


def _compute_features(df: pd.DataFrame) -> pd.DataFrame:
    close = df["close"]

    # Selected positionally, not by exact column name -- pandas_ta's
    # column-name suffix format isn't stable across releases, but column
    # order (MACD line, histogram, signal line) is (same rationale as
    # bollinger_mean_reversion.py's band selection).
    macd_hist = ta.macd(close).iloc[:, 1]
    atr_14 = ta.atr(df["high"], df["low"], close, length=14)
    sma_20 = ta.sma(close, length=20)

    return pd.DataFrame({
        "rsi_14": ta.rsi(close, length=14),
        "macd_hist": macd_hist,
        "atr_14_pct": atr_14 / close,
        "return_1d": close.pct_change(1),
        "return_5d": close.pct_change(5),
        "return_10d": close.pct_change(10),
        "close_vs_sma_20_pct": (close - sma_20) / sma_20,
    })


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Returns (entries, exits, confidence) -- confidence is the
    walk-forward model's predicted up-probability at each bar (NaN where
    no trained model yet covers that bar).

    Side effect: writes params["model_diagnostics"] (model type, top
    features by importance from the most recent retrain, number of
    retrain blocks) into the *caller's* params dict -- the same dict
    main.py threads straight through to the API response's `params`
    field, mirroring how pairs_trading threads symbol_b through params.
    This surfaces per-request model explainability without a
    BacktestResult schema change.
    """
    train_window = params.get("train_window", DEFAULT_PARAMS["train_window"])
    retrain_every = params.get("retrain_every", DEFAULT_PARAMS["retrain_every"])
    n_estimators = params.get("n_estimators", DEFAULT_PARAMS["n_estimators"])
    max_depth = params.get("max_depth", DEFAULT_PARAMS["max_depth"])
    min_confidence = params.get("min_confidence", DEFAULT_PARAMS["min_confidence"])

    features = _compute_features(df)
    # Next-bar direction -- a forward-looking label, which is correct and
    # necessary (it's what each row's model is trained to predict), but
    # never used as a feature. The walk-forward loop below is the part
    # that must never let a training slice include a row at or after the
    # bar being predicted.
    target = (df["close"].shift(-1) > df["close"]).astype(int)

    n = len(df)
    confidence = pd.Series(np.nan, index=df.index)
    importances_by_block: list[dict] = []

    start = train_window
    while start < n:
        train_rows = features.iloc[start - train_window:start]
        train_labels = target.iloc[start - train_window:start]
        valid_train = train_rows.notna().all(axis=1) & train_labels.notna()
        X_train = train_rows[valid_train]
        y_train = train_labels[valid_train]

        predict_end = min(start + retrain_every, n)
        predict_rows = features.iloc[start:predict_end]
        valid_predict = predict_rows.notna().all(axis=1)

        if len(X_train) >= MIN_TRAIN_ROWS and y_train.nunique() == 2:
            model = GradientBoostingClassifier(
                n_estimators=n_estimators, max_depth=max_depth, random_state=42,
            )
            model.fit(X_train, y_train)

            if valid_predict.any():
                proba_up = model.predict_proba(predict_rows[valid_predict])[:, 1]
                confidence.loc[predict_rows.index[valid_predict]] = proba_up

            importances_by_block.append(dict(zip(FEATURE_NAMES, model.feature_importances_)))

        start += retrain_every

    entries = confidence > min_confidence
    exits = confidence < 0.5

    if importances_by_block:
        latest = importances_by_block[-1]
        top_features = sorted(latest.items(), key=lambda kv: kv[1], reverse=True)[:3]
        params["model_diagnostics"] = {
            "model_type": "GradientBoostingClassifier",
            "top_features": [{"feature": name, "importance": round(float(value), 4)} for name, value in top_features],
            "retrain_blocks": len(importances_by_block),
        }

    return entries.fillna(False), exits.fillna(False), confidence


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits, _confidence = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_ml_signal.py -v`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/ml_signal.py analytics/tests/test_ml_signal.py analytics/requirements.txt
git commit -m "feat(strategies): add ML signal (walk-forward, explainable) strategy"
```

---

### Task 2: Register ml_signal + `/backtest` endpoint smoke test

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Modify: `analytics/schemas.py`
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `ml_signal.DEFAULT_PARAMS`/`ml_signal` module from Task 1.
- Produces: `STRATEGY_REGISTRY["ml_signal"]` entry — later tasks (Laravel, frontend) rely on this exact string key. Confirms the `model_diagnostics` side-channel survives the full request/response round trip through `main.py`'s generic `run_vectorbt` dispatch (no special-casing needed, unlike `pairs_trading`).

- [ ] **Step 1: Write the failing test**

Append to `analytics/tests/test_backtest_endpoint.py` (add `import numpy as np` at the top of the file if not already present):

```python
def test_backtest_ml_signal_returns_metrics_and_model_diagnostics(mocker):
    # ml_signal needs enough bars for at least one walk-forward training
    # block -- the shared 100-bar _synthetic_uptrend_df fixture is too
    # short for even a small train_window, so this test uses its own
    # longer fixture (same shape/columns, just more bars). It must also
    # have genuine up/down variation, not a monotonic trend -- a
    # monotonic series makes the target column single-class, which the
    # module's MIN_TRAIN_ROWS/nunique()==2 guard correctly skips training
    # for (confirmed by hitting this exact bug during implementation with
    # a monotonic fixture: zero trained blocks, no model_diagnostics).
    idx = pd.date_range("2023-01-01", periods=180, freq="D", tz="UTC")
    close = pd.Series(100 + np.cumsum(np.sin(np.arange(180) / 5.0) + 0.01), index=idx)
    df = pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})
    mocker.patch("main.fetch_ohlcv_cached", return_value=df)

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "ml_signal",
            "params": {"train_window": 60, "retrain_every": 20},
            "start_date": "2023-01-01",
            "end_date": "2023-06-30",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "ml_signal"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
    assert body["params"]["model_diagnostics"]["model_type"] == "GradientBoostingClassifier"
    assert 1 <= len(body["params"]["model_diagnostics"]["top_features"]) <= 3
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k ml_signal`
Expected: FAIL — `ml_signal` isn't a recognized `StrategyName` yet (Pydantic validation error).

- [ ] **Step 3: Register the strategy**

In `analytics/strategies/__init__.py`, add the import and a registry entry:

```python
from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion, momentum, pairs_trading, ml_signal, custom
```

```python
    "ml_signal": {
        "engine": "vectorbt",
        "module": ml_signal,
        "default_params": ml_signal.DEFAULT_PARAMS,
        "interval": "1d",
    },
```

(Insert right before the `"custom"` entry, matching precedent.)

In `analytics/schemas.py`, add `"ml_signal"` to `StrategyName`:

```python
StrategyName = Literal[
    "ma_crossover", "rsi_mean_reversion", "breakout", "bollinger_mean_reversion", "momentum",
    "pairs_trading", "ml_signal", "custom", "method_714",
]
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k ml_signal`
Expected: PASS

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/__init__.py analytics/schemas.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(strategies): register ml_signal in STRATEGY_REGISTRY"
```

---

### Task 3: Laravel validation + attribution

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: the string key `"ml_signal"` and the `params.model_diagnostics` shape from Task 1/2 (`model_type: string`, `top_features: [{feature, importance}]`, `retrain_blocks: int`).
- Produces: nothing new for later tasks — this task's effect is entirely in validation/attribution behavior.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/BacktestControllerTest.php`, inside the `BacktestControllerTest` class:

```php
    public function test_store_accepts_ml_signal_strategy_and_surfaces_model_diagnostics(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ml_signal',
                'params' => [
                    'train_window' => 500,
                    'retrain_every' => 20,
                    'n_estimators' => 100,
                    'max_depth' => 3,
                    'min_confidence' => 0.55,
                    'model_diagnostics' => [
                        'model_type' => 'GradientBoostingClassifier',
                        'top_features' => [
                            ['feature' => 'return_1d', 'importance' => 0.74],
                            ['feature' => 'macd_hist', 'importance' => 0.17],
                        ],
                        'retrain_blocks' => 8,
                    ],
                ],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 6.0,
                    'win_rate_pct' => 60.0,
                    'max_drawdown_pct' => -3.0,
                    'sharpe_ratio' => 0.9,
                    'trade_count' => 15,
                    'losing_trade_count' => 6,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ml_signal',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'ML Signal (Explainable)')
                && str_contains($attribution, 'GradientBoostingClassifier')
                && str_contains($attribution, 'return_1d, macd_hist')
                && ! str_contains($attribution, 'model_diagnostics=');
        });
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=ml_signal`
Expected: FAIL — `ml_signal` isn't in the `strategy` validation allow-list yet, so `assertOk()` fails on a 422.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, update the `strategy` validation rule:

```php
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714,breakout,bollinger_mean_reversion,momentum,pairs_trading,ml_signal,custom',
```

In `backend/app/Services/DisclosureFormatter.php`:

1. Exclude `model_diagnostics` from the generic params listing (it's an internal explainability annotation the analytics service writes as a side channel, not a strategy hyperparameter — dumping it as raw JSON alongside `lookback=20, entry_z=2` etc. would be noise; it gets its own formatted sentence instead):

```php
        $paramsStr = collect($backtestResult['params'] ?? [])
            ->except('model_diagnostics')
            ->map(fn ($v, $k) => is_scalar($v) ? "{$k}={$v}" : "{$k}=" . json_encode($v))
            ->implode(', ');
```

2. Add to `STRATEGY_LABELS`:

```php
        'ml_signal' => 'ML Signal (Explainable)',
```

3. Add a new branch in `attribution()`, alongside the existing `method_714`/`pairs_trading` branches:

```php
        if ($strategyKey === 'ml_signal') {
            $diagnostics = $backtestResult['params']['model_diagnostics'] ?? null;
            if ($diagnostics) {
                $featureList = collect($diagnostics['top_features'] ?? [])
                    ->map(fn ($f) => $f['feature'])
                    ->implode(', ');
                $attribution .= sprintf(
                    ' Signal from a %s retrained every %s bars on a trailing walk-forward window '
                    . '(%s retrain cycles this run); top features by importance: %s. A prediction, '
                    . 'not a rule — treat it with the same skepticism as any other model output.',
                    $diagnostics['model_type'] ?? 'model',
                    $backtestResult['params']['retrain_every'] ?? '?',
                    $diagnostics['retrain_blocks'] ?? '?',
                    $featureList ?: 'none available'
                );
            }
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ml_signal`
Expected: PASS

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/app/Services/DisclosureFormatter.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): accept ml_signal strategy, surface model diagnostics in attribution"
```

---

### Task 4: Frontend strategy dropdowns

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/history.html`

**Interfaces:**
- Consumes: the string key `"ml_signal"` from Task 2 (must match exactly as `<option value="...">`).
- Produces: nothing new for later tasks — this is the final task in the plan. Unlike `pairs_trading`, `ml_signal` needs no JS changes to `backtest.js`/`history.js` — it uses the standard single-symbol payload shape every other built-in strategy already sends.

- [ ] **Step 1: Update `backtest.html`'s strategy dropdown**

```html
      <option value="pairs_trading">Pairs Trading (Stat-Arb)</option>
      <option value="ml_signal">ML Signal (Explainable)</option>
      <option value="method_714">714 Method</option>
```

- [ ] **Step 2: Update `history.html`'s strategy filter dropdown**

```html
          <option value="pairs_trading">Pairs Trading (Stat-Arb)</option>
          <option value="ml_signal">ML Signal (Explainable)</option>
          <option value="method_714">714 Method</option>
```

- [ ] **Step 3: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/backtest.html`, select "ML Signal (Explainable)", fill in a symbol/date range covering at least ~2 years of daily bars (the default `train_window=500` needs it — e.g. `AAPL`, equity, `2020-01-01` to `2024-01-01`), click "Run backtest", and confirm it renders a result with no console errors — the disclosure text should mention "GradientBoostingClassifier" and "top features by importance".
3. Open `http://localhost:3000/history.html` and confirm "ML Signal (Explainable)" appears in the strategy filter dropdown.
4. Stop the dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/backtest.html frontend/history.html
git commit -m "feat(frontend): add ml_signal to strategy dropdowns"
```
