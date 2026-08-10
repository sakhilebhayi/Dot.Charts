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
