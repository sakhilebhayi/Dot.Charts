import pandas as pd
import vectorbt as vbt

DEFAULT_PARAMS = {
    "lookback": 252,
    "skip": 21,
    "roc_window": 10,
    "roc_threshold": 0.0,
}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    lookback = params.get("lookback", DEFAULT_PARAMS["lookback"])
    skip = params.get("skip", DEFAULT_PARAMS["skip"])
    roc_window = params.get("roc_window", DEFAULT_PARAMS["roc_window"])
    roc_threshold = params.get("roc_threshold", DEFAULT_PARAMS["roc_threshold"])

    # 12-month-minus-most-recent-month momentum: skip excludes the most
    # recent `skip` bars from the lookback measurement, the standard
    # formulation used to avoid short-term reversal contaminating a
    # longer-term trend read.
    mom = df["close"].shift(skip) / df["close"].shift(lookback) - 1
    roc = df["close"].pct_change(roc_window)

    # Entry fires on mom's own crossover (the trend filter turning on),
    # with roc used as a level confirmation (short-term momentum already
    # positive) rather than requiring both to cross on the exact same bar.
    # roc, being a much shorter window than lookback, typically turns
    # positive long before the slower lookback-window mom filter does --
    # requiring simultaneous crossings would make entries fire close to
    # never on any real trending series.
    mom_cross_up = (mom > 0) & (mom.shift(1) <= 0)
    entries = mom_cross_up & (roc > roc_threshold)

    exits = (mom < 0) & (mom.shift(1) >= 0)

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
