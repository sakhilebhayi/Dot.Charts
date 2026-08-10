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
