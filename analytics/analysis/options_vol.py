import numpy as np
import pandas as pd
import yfinance as yf

from data.cache import fetch_ohlcv_cached
from data.fetch import DataFetchError
from pricing.black_scholes import bs_implied_vol

DEFAULT_PARAMS = {
    "target_expiry_days": 30,
    "realized_vol_window": 20,
    "realized_vol_lookback": 252,
    "rank_high": 80,
    "rank_low": 20,
    "skew_threshold": 0.05,
    "risk_free_rate": 0.04,
}


class OptionsDataError(Exception):
    pass


def _fetch_option_chain(symbol: str, target_expiry_days: int) -> tuple[str, pd.DataFrame, pd.DataFrame, float]:
    """Isolated at the module boundary so tests can mock exactly this
    function rather than reaching into yfinance's Ticker internals.
    Returns (expiry, calls, puts, spot)."""
    ticker = yf.Ticker(symbol)
    expiries = ticker.options
    if not expiries:
        raise OptionsDataError(f"No options chain available for symbol '{symbol}'")

    today = pd.Timestamp.utcnow().normalize()
    target = today + pd.Timedelta(days=target_expiry_days)
    # Nearest available expiry to the target horizon -- not necessarily the
    # soonest expiry, which is often 0-1 DTE and carries wide, stale quotes
    # that make for a noisy skew read (confirmed while prototyping this
    # module: the nearest listed AAPL expiry showed >200% "implied vols"
    # from zero-open-interest contracts, vs. sane ~25-30% readings a
    # month out).
    expiry = min(expiries, key=lambda e: abs((pd.Timestamp(e, tz="UTC") - target).days))

    chain = ticker.option_chain(expiry)
    history = ticker.history(period="1d")
    if history.empty:
        raise OptionsDataError(f"No spot price available for symbol '{symbol}'")
    spot = float(history["Close"].iloc[-1])

    return expiry, chain.calls, chain.puts, spot


def _realized_vol_rank(df: pd.DataFrame, window: int, lookback: int) -> dict:
    """Annualized rolling realized volatility of the underlying, and the
    current reading's percentile rank within its own trailing history --
    an explicit proxy for true IV rank, not IV rank itself. yfinance has
    no historical implied-volatility endpoint, so this is what's honestly
    computable from data already fetched elsewhere in this codebase (see
    the design spec's IV-rank-source decision)."""
    log_returns = np.log(df["close"] / df["close"].shift(1))
    realized_vol = log_returns.rolling(window).std() * np.sqrt(252) * 100

    history = realized_vol.dropna().tail(lookback)
    if history.empty:
        raise OptionsDataError("Not enough price history to compute realized volatility")

    current = float(history.iloc[-1])
    rank_pct = float((history <= current).mean() * 100)

    return {"current_annualized_pct": round(current, 2), "rank_pct": round(rank_pct, 1), "window_days": window}


def _atm_skew(calls: pd.DataFrame, puts: pd.DataFrame, spot: float, expiry: str, risk_free_rate: float) -> dict:
    """Put-call skew at the strike nearest current spot for each side
    (calls and puts list different strikes, so "nearest to spot" is
    computed independently for each rather than requiring an exact
    shared strike). Prefers yfinance's own impliedVolatility field;
    falls back to a Black-Scholes solve against lastPrice only when
    that field is missing or non-positive (a known gap for
    illiquid/stale-quoted contracts)."""
    years_to_expiry = max((pd.Timestamp(expiry, tz="UTC") - pd.Timestamp.utcnow()).days, 0) / 365.0

    def _nearest_atm_iv(contracts: pd.DataFrame, option_type: str) -> tuple[float, float] | None:
        if contracts.empty:
            return None
        row = contracts.iloc[(contracts["strike"] - spot).abs().argsort().iloc[0]]
        iv = float(row["impliedVolatility"])
        if iv <= 0:
            solved = bs_implied_vol(
                price=float(row["lastPrice"]), S=spot, K=float(row["strike"]),
                T=years_to_expiry, r=risk_free_rate, option_type=option_type,
            )
            if solved is None:
                return None
            iv = float(solved)
        return float(row["strike"]), iv

    call_atm = _nearest_atm_iv(calls, "call")
    put_atm = _nearest_atm_iv(puts, "put")
    if call_atm is None or put_atm is None:
        raise OptionsDataError("Could not determine a usable ATM implied volatility for both calls and puts")

    call_strike, call_iv = call_atm
    put_strike, put_iv = put_atm

    return {
        "call_strike": call_strike,
        "call_iv": round(call_iv, 4),
        "put_strike": put_strike,
        "put_iv": round(put_iv, 4),
        "skew": round(put_iv - call_iv, 4),
    }


def compute_vol_signal(symbol: str, asset_class: str, params: dict) -> dict:
    target_expiry_days = params.get("target_expiry_days", DEFAULT_PARAMS["target_expiry_days"])
    realized_vol_window = params.get("realized_vol_window", DEFAULT_PARAMS["realized_vol_window"])
    realized_vol_lookback = params.get("realized_vol_lookback", DEFAULT_PARAMS["realized_vol_lookback"])
    rank_high = params.get("rank_high", DEFAULT_PARAMS["rank_high"])
    rank_low = params.get("rank_low", DEFAULT_PARAMS["rank_low"])
    skew_threshold = params.get("skew_threshold", DEFAULT_PARAMS["skew_threshold"])
    risk_free_rate = params.get("risk_free_rate", DEFAULT_PARAMS["risk_free_rate"])

    expiry, calls, puts, spot = _fetch_option_chain(symbol, target_expiry_days)

    end_date = pd.Timestamp.utcnow().strftime("%Y-%m-%d")
    start_date = (pd.Timestamp.utcnow() - pd.Timedelta(days=realized_vol_lookback + realized_vol_window + 30)).strftime("%Y-%m-%d")
    try:
        underlying_df = fetch_ohlcv_cached(symbol, asset_class, start_date, end_date, interval="1d")
    except DataFetchError as exc:
        raise OptionsDataError(str(exc))

    realized_vol = _realized_vol_rank(underlying_df, realized_vol_window, realized_vol_lookback)
    skew = _atm_skew(calls, puts, spot, expiry, risk_free_rate)

    if realized_vol["rank_pct"] >= rank_high:
        vol_regime = "elevated"
    elif realized_vol["rank_pct"] <= rank_low:
        vol_regime = "low"
    else:
        vol_regime = "normal"

    if skew["skew"] > skew_threshold:
        skew_regime = "put_skew_elevated"
    elif skew["skew"] < -skew_threshold:
        skew_regime = "call_skew_elevated"
    else:
        skew_regime = "balanced"

    return {
        "symbol": symbol,
        "asset_class": asset_class,
        "spot": round(spot, 2),
        "expiry_used": expiry,
        "realized_vol": realized_vol,
        "skew": skew,
        "vol_regime": vol_regime,
        "skew_regime": skew_regime,
        "as_of": pd.Timestamp.utcnow().isoformat(),
    }
