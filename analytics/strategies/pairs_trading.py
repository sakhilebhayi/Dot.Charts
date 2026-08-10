import pandas as pd
import vectorbt as vbt
from statsmodels.tsa.stattools import coint

DEFAULT_PARAMS = {
    "lookback": 20,
    "entry_z": 2.0,
    "exit_z": 0.5,
    "stop_z": 4.0,
    "coint_pvalue_max": 0.05,
}


def generate_signals(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Returns (entries, exits, spread) -- spread is the synthetic series
    run() feeds to vbt.Portfolio.from_signals (after rebasing to a positive
    price level; see run())."""
    lookback = params.get("lookback", DEFAULT_PARAMS["lookback"])
    entry_z = params.get("entry_z", DEFAULT_PARAMS["entry_z"])
    exit_z = params.get("exit_z", DEFAULT_PARAMS["exit_z"])
    stop_z = params.get("stop_z", DEFAULT_PARAMS["stop_z"])
    coint_pvalue_max = params.get("coint_pvalue_max", DEFAULT_PARAMS["coint_pvalue_max"])

    price_a = df_a["close"]
    price_b = df_b["close"]

    # Correctness gate, not a tunable knob a caller can silently disable:
    # if the two symbols aren't genuinely cointegrated over this window,
    # trading their "spread" is trading noise, not a mean-reverting
    # relationship. Fail closed -- no signals at all rather than a
    # spurious spread.
    _, pvalue, _ = coint(price_a, price_b)
    if pvalue > coint_pvalue_max:
        empty = pd.Series(False, index=price_a.index)
        return empty, empty, price_a - price_b

    # Rolling hedge ratio via the closed-form single-regressor OLS slope
    # (cov/var), refit every bar over the trailing `lookback` window --
    # equivalent to a rolling univariate OLS of price_a on price_b without
    # an explicit per-bar regression loop, and avoids a second, heavier
    # statsmodels call on every bar.
    hedge_ratio = price_a.rolling(lookback).cov(price_b) / price_b.rolling(lookback).var()
    spread = price_a - hedge_ratio * price_b
    z = (spread - spread.rolling(lookback).mean()) / spread.rolling(lookback).std()

    entries = (z < -entry_z) & (z.shift(1) >= -entry_z)
    mean_revert_exit = (z > -exit_z) & (z.shift(1) <= -exit_z)
    stop_exit = z < -stop_z
    exits = mean_revert_exit | stop_exit

    return entries.fillna(False), exits.fillna(False), spread


def run(df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits, spread = generate_signals(df_a, df_b, params)

    # vbt.Portfolio.from_signals treats its price argument as a literal
    # tradeable price -- the raw spread is a difference, not a price, and
    # can go negative. Rebase it onto price_a's starting level before
    # handing it to the engine. This is a constant additive shift, so it
    # changes no entry/exit timing (those depend only on the z-score,
    # which is invariant to adding a constant to the spread it's computed
    # from) -- it only makes the series look like a normal positive-valued
    # instrument price to the portfolio engine.
    synthetic_price = spread + df_a["close"].iloc[0]
    return vbt.Portfolio.from_signals(synthetic_price, entries, exits, freq="1D", init_cash=10_000)
