# Options Vol Signal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a current-state options volatility-regime read — realized-vol rank (a disclosed proxy for true IV rank) plus ATM put-call skew from the live options chain — as a new endpoint family outside `/backtest`, with its own standalone frontend page.

**Architecture:** A new analytics module (`analytics/analysis/options_vol.py`, mirroring `chart_analysis.py`'s "computation, not a strategy" shape) computing two independent reads — realized volatility percentile-ranked against its own trailing history (from OHLCV data already fetched via the existing `fetch_ohlcv_cached` cache) and ATM put-call implied-vol skew (from `yfinance`'s live options chain, with a small local Black-Scholes module as a fallback IV solver for stale/zero-quoted contracts). Exposed via `GET /options/vol-signal/{symbol}` in the analytics service and `GET /api/options/vol-signal/{symbol}` in Laravel — a new endpoint family, not shoehorned into `/backtest`, since there's no portfolio/entries/exits/trades. A new standalone `options.html` page, linked from every other page's nav.

**Tech Stack:** Python `pandas`/`numpy`/`yfinance` (existing) + `scipy` (new declared dependency, for `norm.cdf`/`norm.pdf` in the Black-Scholes solver — was already present transitively), PHP/Laravel, vanilla JS/HTML.

## Scope Decisions (resolved during brainstorming — see spec's Open Questions, now closed)

- **IV-rank source:** realized-vol proxy, not true historical IV. `yfinance` has no historical implied-volatility endpoint at all, so `_realized_vol_rank()` computes annualized realized volatility from the underlying's own OHLCV history and ranks the current reading against its own trailing window — explicitly labeled a proxy in both the field name (`realized_vol`, not `iv_rank`) and the disclosure text, never presented as if it were true IV rank.
- **Scope: signal read only, not position backtesting.** `yfinance`'s `option_chain()` only exposes the *current* live chain — there is no historical strike/expiry/IV series available anywhere in the stack to backtest actual option positions against. Building position backtesting against data that doesn't exist would mean either faking historical quotes or not shipping something honest; this round ships only the buildable piece (today's regime read). Real position backtesting is out of scope for this round, blocked on sourcing genuine historical options-chain data — a future spec's problem, not a design choice made lightly.
- **API shape:** new endpoint family (`/options/vol-signal/{symbol}`), not forced into `/backtest`'s request/response shape — this isn't a backtest (no entries, exits, portfolio, or trades), it's a current-state read, same category as `/chart-analysis`.
- **Frontend surface:** new standalone page (`options.html`), not a panel bolted onto `backtest.html` — keeps a live-read UI separate from the strategy-backtest flow it isn't part of.

## Global Constraints

- Never present the realized-vol proxy as true IV rank — every response and every disclosure sentence says "realized-vol rank" / "proxy," not "IV rank" (per the resolved IV-rank-source decision above).
- The nearest-to-30-days expiry is selected, not the soonest-listed expiry — **found while prototyping, not in the original spec**: the nearest listed expiry for a real symbol was often 0-1 DTE with zero open interest and implausible (>200%) implied vols from stale/wide quotes; a ~30-day horizon is the standard "front-month-ish" convention and gave sane readings in the same live check.
- `yfinance`'s `impliedVolatility` field is the primary IV source (it's free and already fetched); Black-Scholes is used only as a fallback when that field is `<= 0` (stale/illiquid quote), solving IV from `lastPrice` instead — never fabricating a number when neither source is usable (the fallback itself returns `None` on non-convergence, and the caller raises `OptionsDataError` rather than guessing).
- `OptionsDataError` (analytics-side) → Laravel's `RuntimeException` → **503**, matching `BacktestController`'s existing precedent exactly (`AnalyticsServiceClient` doesn't preserve the upstream status code distinctly, so every analytics-service failure maps to one Laravel status code, not a per-cause one).
- New rate limiter `options-vol` (20/hour by IP, unauthenticated) — same reasoning as the existing `chart-analysis` limiter: a real external API cost (a live options-chain fetch plus an OHLCV fetch) on every call, unauthenticated by design, so it needs its own ceiling rather than relying on the global `api` backstop alone.

---

### Task 1: Black-Scholes reference module

**Files:**
- Create: `analytics/pricing/__init__.py` (empty)
- Create: `analytics/pricing/black_scholes.py`
- Test: `analytics/tests/test_black_scholes.py`
- Modify: `analytics/requirements.txt`

**Interfaces:**
- Produces: `bs_price(S, K, T, r, sigma, option_type="call") -> float`, `bs_implied_vol(price, S, K, T, r, option_type="call", tol=1e-6, max_iter=100) -> float | None` — Task 2 (`options_vol.py`) relies on both exact signatures.

- [ ] **Step 1: Add the new dependency**

Append to `analytics/requirements.txt`:

```
scipy>=1.10
```

- [ ] **Step 2: Write the failing test**

```python
# analytics/tests/test_black_scholes.py
import math

import pytest
from pricing.black_scholes import bs_implied_vol, bs_price


def test_bs_price_matches_known_textbook_values():
    # S=100, K=100, T=1y, r=5%, sigma=20% -- a standard reference case
    # (e.g. Hull's "Options, Futures, and Other Derivatives").
    call = bs_price(100, 100, 1, 0.05, 0.2, "call")
    put = bs_price(100, 100, 1, 0.05, 0.2, "put")

    assert call == pytest.approx(10.4506, abs=1e-3)
    assert put == pytest.approx(5.5735, abs=1e-3)


def test_bs_price_satisfies_put_call_parity():
    S, K, T, r, sigma = 150.0, 140.0, 0.5, 0.03, 0.35
    call = bs_price(S, K, T, r, sigma, "call")
    put = bs_price(S, K, T, r, sigma, "put")

    # C - P = S - K*e^(-rT), independent of sigma -- a model-free
    # no-arbitrage identity, so this holds regardless of the vol input.
    lhs = call - put
    rhs = S - K * math.exp(-r * T)
    assert lhs == pytest.approx(rhs, abs=1e-6)


def test_bs_price_falls_back_to_intrinsic_value_at_expiry():
    # T=0: no time value left, price must equal intrinsic value exactly.
    assert bs_price(110, 100, 0, 0.05, 0.2, "call") == 10.0
    assert bs_price(90, 100, 0, 0.05, 0.2, "call") == 0.0
    assert bs_price(90, 100, 0, 0.05, 0.2, "put") == 10.0


def test_bs_implied_vol_round_trips_a_price():
    S, K, T, r, sigma_true = 200.0, 195.0, 0.25, 0.04, 0.28
    price = bs_price(S, K, T, r, sigma_true, "call")

    recovered = bs_implied_vol(price, S, K, T, r, "call")

    assert recovered == pytest.approx(sigma_true, abs=1e-4)


def test_bs_implied_vol_returns_none_for_an_unconverging_input():
    # A price nobody would ever legitimately quote (larger than the
    # underlying itself) -- the solver must give up cleanly, not throw or
    # return a fabricated number.
    assert bs_implied_vol(price=500.0, S=100.0, K=100.0, T=0.1, r=0.05, option_type="call") is None
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_black_scholes.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'pricing'`

- [ ] **Step 4: Write the implementation**

```python
# analytics/pricing/__init__.py
```
(empty file — marks the directory as a package)

```python
# analytics/pricing/black_scholes.py
import math

from scipy.stats import norm

# Reference implementation, informed by the standard closed-form
# Black-Scholes formula (financial-models-numerical-methods' treatment of
# it, reimplemented locally rather than installed as a dependency -- see
# docs/superpowers/specs/2026-08-10-options-vol-strategy-design.md).
#
# Used only as a cross-check / fallback: yfinance's own `impliedVolatility`
# field is the primary IV source (it's free and already fetched), but it is
# sometimes 0 or missing for illiquid/stale-quoted contracts. bs_implied_vol
# recovers an IV estimate from a contract's lastPrice in that situation.


def bs_price(S: float, K: float, T: float, r: float, sigma: float, option_type: str = "call") -> float:
    """European option price. T is in years, r and sigma are annualized
    decimals (0.05 = 5%). Falls back to intrinsic value at/after expiry or
    for a degenerate (zero-vol) input, rather than dividing by zero."""
    if T <= 0 or sigma <= 0:
        return max(0.0, S - K) if option_type == "call" else max(0.0, K - S)

    d1 = (math.log(S / K) + (r + 0.5 * sigma ** 2) * T) / (sigma * math.sqrt(T))
    d2 = d1 - sigma * math.sqrt(T)

    if option_type == "call":
        return S * norm.cdf(d1) - K * math.exp(-r * T) * norm.cdf(d2)
    return K * math.exp(-r * T) * norm.cdf(-d2) - S * norm.cdf(-d1)


def bs_implied_vol(
    price: float, S: float, K: float, T: float, r: float, option_type: str = "call",
    tol: float = 1e-6, max_iter: int = 100,
) -> float | None:
    """Newton-Raphson solve for the implied volatility that reprices a
    quoted option price. Returns None rather than raising when it fails to
    converge (a stale/crossed quote outside any achievable no-arbitrage
    bound) -- the caller falls back to skipping that contract, not to a
    fabricated number."""
    if T <= 0 or price <= 0:
        return None

    sigma = 0.3  # reasonable starting guess for a Newton-Raphson search
    for _ in range(max_iter):
        model_price = bs_price(S, K, T, r, sigma, option_type)
        d1 = (math.log(S / K) + (r + 0.5 * sigma ** 2) * T) / (sigma * math.sqrt(T))
        vega = S * norm.pdf(d1) * math.sqrt(T)
        if vega < 1e-8:
            return None

        diff = model_price - price
        if abs(diff) < tol:
            return sigma

        sigma -= diff / vega
        if sigma <= 0:
            return None

    return None
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_black_scholes.py -v`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add analytics/pricing/ analytics/tests/test_black_scholes.py analytics/requirements.txt
git commit -m "feat(pricing): add Black-Scholes pricing/IV-solver reference module"
```

---

### Task 2: Options vol signal computation module

**Files:**
- Create: `analytics/analysis/options_vol.py`
- Test: `analytics/tests/test_options_vol.py`

**Interfaces:**
- Consumes: `bs_implied_vol` from Task 1; `fetch_ohlcv_cached`/`DataFetchError` (existing, from `data.cache`/`data.fetch`); `yfinance` directly (new import in this module).
- Produces: `DEFAULT_PARAMS` (dict), `OptionsDataError` (exception class), `compute_vol_signal(symbol: str, asset_class: str, params: dict) -> dict` — Task 3 (endpoint) relies on this exact function signature and the exact response dict shape (`symbol`, `asset_class`, `spot`, `expiry_used`, `realized_vol: {current_annualized_pct, rank_pct, window_days}`, `skew: {call_strike, call_iv, put_strike, put_iv, skew}`, `vol_regime`, `skew_regime`, `as_of`). Also produces `_fetch_option_chain` and `_realized_vol_rank` as separately mockable module-level functions — Task 2's own tests patch these directly rather than mocking `yfinance`'s `Ticker` internals.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_options_vol.py
import numpy as np
import pandas as pd
import pytest

import analysis.options_vol as options_vol
from data.fetch import DataFetchError


def _underlying_df(seed: int = 1, periods: int = 280) -> pd.DataFrame:
    idx = pd.date_range("2024-01-01", periods=periods, freq="D", tz="UTC")
    rng = np.random.default_rng(seed)
    close = pd.Series(150 + np.cumsum(rng.normal(0, 1.5, periods)), index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _future_expiry(days: int = 30) -> str:
    # Always computed relative to "now" rather than a hardcoded date --
    # yfinance's real `ticker.options` only ever lists future expiries, and
    # a hardcoded past date silently breaks the Black-Scholes fallback path
    # (T<=0 makes bs_implied_vol refuse to solve), which is exactly what
    # happened prototyping this test before this fixture existed.
    return (pd.Timestamp.utcnow() + pd.Timedelta(days=days)).strftime("%Y-%m-%d")


def test_compute_vol_signal_reports_elevated_put_skew_from_yfinance_iv(mocker):
    calls = pd.DataFrame({"strike": [145, 150, 155], "impliedVolatility": [0.25, 0.22, 0.20], "lastPrice": [8, 5, 3]})
    puts = pd.DataFrame({"strike": [145, 150, 155], "impliedVolatility": [0.30, 0.28, 0.26], "lastPrice": [3, 5, 8]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["symbol"] == "AAPL"
    assert result["spot"] == 150.0
    assert result["skew"]["call_strike"] == 150.0
    assert result["skew"]["put_strike"] == 150.0
    assert result["skew"]["skew"] == pytest.approx(0.06, abs=1e-6)
    assert result["skew_regime"] == "put_skew_elevated"


def test_compute_vol_signal_falls_back_to_black_scholes_when_iv_is_zero(mocker):
    # impliedVolatility=0 is yfinance's signal for a stale/illiquid quote --
    # the module must recover an IV estimate from lastPrice via
    # Black-Scholes instead of treating 0% vol as real.
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.0], "lastPrice": [5.5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.0], "lastPrice": [5.0]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["skew"]["call_iv"] > 0
    assert result["skew"]["put_iv"] > 0


@pytest.mark.parametrize(
    "rank_pct,expected_regime",
    [(95.0, "elevated"), (50.0, "normal"), (5.0, "low")],
)
def test_vol_regime_classification_thresholds(mocker, rank_pct, expected_regime):
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())
    mocker.patch(
        "analysis.options_vol._realized_vol_rank",
        return_value={"current_annualized_pct": 30.0, "rank_pct": rank_pct, "window_days": 20},
    )

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["vol_regime"] == expected_regime


def test_compute_vol_signal_wraps_underlying_data_fetch_failure(mocker):
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch(
        "analysis.options_vol.fetch_ohlcv_cached",
        side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"),
    )

    with pytest.raises(options_vol.OptionsDataError, match="BADSYMBOL"):
        options_vol.compute_vol_signal("BADSYMBOL", "equity", options_vol.DEFAULT_PARAMS)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_options_vol.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'analysis.options_vol'`

- [ ] **Step 3: Write the implementation**

```python
# analytics/analysis/options_vol.py
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_options_vol.py -v`
Expected: PASS (6 tests, 3 parametrized cases counted individually)

- [ ] **Step 5: Commit**

```bash
git add analytics/analysis/options_vol.py analytics/tests/test_options_vol.py
git commit -m "feat(analysis): add options vol signal computation (realized-vol rank + skew)"
```

---

### Task 3: FastAPI endpoint

**Files:**
- Modify: `analytics/schemas.py`
- Modify: `analytics/main.py`
- Test: `analytics/tests/test_options_vol_endpoint.py`

**Interfaces:**
- Consumes: `compute_vol_signal`/`OptionsDataError`/`AssetClass` from Task 2/existing `schemas.py`.
- Produces: `GET /options/vol-signal/{symbol}?asset_class=equity` — Task 4 (Laravel's `AnalyticsServiceClient`) relies on this exact path and query param.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_options_vol_endpoint.py
import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _future_expiry(days: int = 30) -> str:
    return (pd.Timestamp.utcnow() + pd.Timedelta(days=days)).strftime("%Y-%m-%d")


def test_options_vol_signal_returns_full_shape(mocker):
    mocker.patch("main.compute_vol_signal", return_value={
        "symbol": "AAPL",
        "asset_class": "equity",
        "spot": 150.0,
        "expiry_used": _future_expiry(),
        "realized_vol": {"current_annualized_pct": 30.0, "rank_pct": 70.0, "window_days": 20},
        "skew": {"call_strike": 150.0, "call_iv": 0.25, "put_strike": 150.0, "put_iv": 0.28, "skew": 0.03},
        "vol_regime": "normal",
        "skew_regime": "balanced",
        "as_of": pd.Timestamp.utcnow().isoformat(),
    })

    response = client.get("/options/vol-signal/AAPL")

    assert response.status_code == 200
    body = response.json()
    assert body["symbol"] == "AAPL"
    assert body["vol_regime"] == "normal"
    assert body["skew"]["skew"] == 0.03


def test_options_vol_signal_returns_422_on_options_data_error(mocker):
    from analysis.options_vol import OptionsDataError

    mocker.patch("main.compute_vol_signal", side_effect=OptionsDataError("No options chain available for symbol 'BADSYMBOL'"))

    response = client.get("/options/vol-signal/BADSYMBOL")

    assert response.status_code == 422
    assert "BADSYMBOL" in response.json()["detail"]


def test_options_vol_signal_defaults_asset_class_to_equity(mocker):
    mock = mocker.patch("main.compute_vol_signal", return_value={
        "symbol": "AAPL",
        "asset_class": "equity",
        "spot": 150.0,
        "expiry_used": _future_expiry(),
        "realized_vol": {"current_annualized_pct": 30.0, "rank_pct": 70.0, "window_days": 20},
        "skew": {"call_strike": 150.0, "call_iv": 0.25, "put_strike": 150.0, "put_iv": 0.28, "skew": 0.03},
        "vol_regime": "normal",
        "skew_regime": "balanced",
        "as_of": pd.Timestamp.utcnow().isoformat(),
    })

    client.get("/options/vol-signal/AAPL")

    assert mock.call_args.args[1] == "equity"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_options_vol_endpoint.py -v`
Expected: FAIL with a 404 (no such route yet)

- [ ] **Step 3: Write the implementation**

In `analytics/schemas.py`, add (after `ValidateRuleRequest`):

```python
class RealizedVolInfo(BaseModel):
    current_annualized_pct: float
    rank_pct: float
    window_days: int


class SkewInfo(BaseModel):
    call_strike: float
    call_iv: float
    put_strike: float
    put_iv: float
    skew: float


class OptionsVolSignalResponse(BaseModel):
    symbol: str
    asset_class: AssetClass
    spot: float
    expiry_used: str
    realized_vol: RealizedVolInfo
    skew: SkewInfo
    vol_regime: Literal["elevated", "normal", "low"]
    skew_regime: Literal["put_skew_elevated", "balanced", "call_skew_elevated"]
    as_of: str
```

In `analytics/main.py`, update the import block:

```python
from schemas import (
    AssetClass, BacktestRequest, BacktestResult, ChartAnalysisRequest, OptionsVolSignalResponse,
    ValidateRuleRequest,
)
```

and add:

```python
from analysis.options_vol import compute_vol_signal, OptionsDataError
```

Add the endpoint (placed before `/validate-rule`):

```python
@app.get("/options/vol-signal/{symbol}", response_model=OptionsVolSignalResponse)
def options_vol_signal(symbol: str, asset_class: AssetClass = "equity"):
    # A current-state read, not a backtest -- no entries/exits/a portfolio,
    # so it deliberately lives outside the /backtest family (same category
    # as /chart-analysis) rather than being forced into that request/
    # response shape. See docs/superpowers/specs/
    # 2026-08-10-options-vol-strategy-design.md for the scope decision.
    try:
        return compute_vol_signal(symbol, asset_class, {})
    except OptionsDataError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_options_vol_endpoint.py -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/schemas.py analytics/main.py analytics/tests/test_options_vol_endpoint.py
git commit -m "feat(analysis): wire options vol signal into a new /options/vol-signal endpoint"
```

---

### Task 4: Laravel — client, controller, route, rate limit, disclosure

**Files:**
- Modify: `backend/app/Services/AnalyticsServiceClient.php`
- Modify: `backend/app/Services/DisclosureFormatter.php`
- Create: `backend/app/Http/Controllers/OptionsVolController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/bootstrap/app.php` (comment accuracy only)
- Test: `backend/tests/Feature/OptionsVolControllerTest.php`

**Interfaces:**
- Consumes: the `GET /options/vol-signal/{symbol}?asset_class=...` endpoint from Task 3.
- Produces: `GET /api/options/vol-signal/{symbol}` — Task 5 (frontend) relies on this exact path and its `{success, result: {..., disclosure: {attribution, risk_disclosure}}}` response shape.

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/OptionsVolControllerTest.php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionsVolControllerTest extends TestCase
{
    private function fakeAnalyticsResponse(): array
    {
        return [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'spot' => 150.0,
            'expiry_used' => '2026-09-11',
            'realized_vol' => ['current_annualized_pct' => 30.0, 'rank_pct' => 70.0, 'window_days' => 20],
            'skew' => ['call_strike' => 150.0, 'call_iv' => 0.25, 'put_strike' => 150.0, 'put_iv' => 0.28, 'skew' => 0.03],
            'vol_regime' => 'normal',
            'skew_regime' => 'balanced',
            'as_of' => '2026-08-10T00:00:00+00:00',
        ];
    }

    public function test_show_returns_vol_signal_with_disclosure(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response($this->fakeAnalyticsResponse(), 200),
        ]);

        $response = $this->getJson('/api/options/vol-signal/AAPL');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.symbol', 'AAPL');
        $response->assertJsonPath('result.vol_regime', 'normal');
        $response->assertJsonStructure([
            'success',
            'result' => [
                'symbol', 'asset_class', 'spot', 'expiry_used', 'realized_vol', 'skew',
                'vol_regime', 'skew_regime', 'as_of',
                'disclosure' => ['attribution', 'risk_disclosure'],
            ],
        ]);
        $response->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'proxy for true IV rank');
        });
    }

    public function test_show_defaults_asset_class_to_equity(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response($this->fakeAnalyticsResponse(), 200),
        ]);

        $this->getJson('/api/options/vol-signal/AAPL')->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/options/vol-signal/AAPL')
                && $request['asset_class'] === 'equity';
        });
    }

    public function test_show_rejects_invalid_asset_class(): void
    {
        $response = $this->getJson('/api/options/vol-signal/AAPL?asset_class=not-a-real-class');

        $response->assertStatus(422);
    }

    public function test_show_returns_503_when_analytics_service_fails(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response(['detail' => "No options chain available for symbol 'BADSYMBOL'"], 422),
        ]);

        $response = $this->getJson('/api/options/vol-signal/BADSYMBOL');

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error', function ($error) {
            return str_contains($error, 'BADSYMBOL');
        });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=OptionsVolControllerTest`
Expected: FAIL — no route registered yet (404s across the board).

- [ ] **Step 3: Write the implementation**

In `backend/app/Services/AnalyticsServiceClient.php`, add (before `validateRule`):

```php
    /**
     * @return array the decoded JSON response (OptionsVolSignalResponse shape:
     *   symbol, asset_class, spot, expiry_used, realized_vol, skew, vol_regime,
     *   skew_regime, as_of)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function optionsVolSignal(string $symbol, string $assetClass): array
    {
        $response = Http::timeout(30)->get(
            "{$this->baseUrl}/options/vol-signal/".rawurlencode($symbol),
            ['asset_class' => $assetClass],
        );

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }
```

Create `backend/app/Http/Controllers/OptionsVolController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OptionsVolController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {}

    /**
     * A current-state volatility-regime read (realized-vol rank proxy +
     * put-call skew), not a backtest -- deliberately outside the
     * /backtests family, same category as ChartAnalysisController. See
     * docs/superpowers/specs/2026-08-10-options-vol-strategy-design.md
     * for the scope decision (signal read, not options-position
     * backtesting -- yfinance has no historical options-chain data to
     * backtest against).
     */
    public function show(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'asset_class' => 'nullable|in:equity,crypto,commodity,forex',
        ]);
        $assetClass = $validated['asset_class'] ?? 'equity';

        try {
            $result = $this->analyticsClient->optionsVolSignal($symbol, $assetClass);
        } catch (RuntimeException $e) {
            // Matches BacktestController's precedent: AnalyticsServiceClient
            // doesn't preserve the upstream status code distinctly (a bad
            // symbol and a connection failure both surface as the same
            // RuntimeException), so every failure here maps to one code.
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'result' => $this->disclosureFormatter->formatVolSignal($result),
        ]);
    }
}
```

In `backend/app/Services/DisclosureFormatter.php`, add (as a new public method, after `attribution()`):

```php
    /**
     * @param array $volSignal the Python service's OptionsVolSignalResponse shape
     * @return array the same array plus a 'disclosure' key
     *
     * A current-state read, not a backtest -- no metrics/trade_count to
     * derive a confidence_band from, so this is a deliberately smaller
     * disclosure shape than format() rather than forcing this response
     * into that method's backtest-shaped assumptions.
     */
    public function formatVolSignal(array $volSignal): array
    {
        $attribution = sprintf(
            'Realized-volatility rank (%s-day window) is a proxy for true IV rank — yfinance has no '
            . 'historical implied-volatility data, so this reflects the underlying\'s own historical price '
            . 'movement, not the options market\'s forward-looking expectation. Put-call skew is read from '
            . 'the %s expiry chain for %s, spot %s.',
            $volSignal['realized_vol']['window_days'] ?? '?',
            $volSignal['expiry_used'] ?? '?',
            $volSignal['symbol'] ?? '?',
            $volSignal['spot'] ?? '?'
        );

        return array_merge($volSignal, [
            'disclosure' => [
                'attribution' => $attribution,
                'risk_disclosure' => self::RISK_DISCLOSURE,
            ],
        ]);
    }
```

In `backend/app/Providers/AppServiceProvider.php`, add a new rate limiter (after the existing `chart-analysis` one):

```php
        // Same reasoning as chart-analysis: unauthenticated, but a real
        // live yfinance options-chain call (plus a second OHLCV call for
        // the realized-vol proxy) every time, so it needs its own cost
        // ceiling rather than relying on the global 'api' backstop alone.
        RateLimiter::for('options-vol', function (Request $request) {
            return Limit::perHour(20)->by('options-vol:ip:'.$request->ip());
        });
```

In `backend/routes/api.php`, add the `OptionsVolController` import and, after the `/backtests` route:

```php
Route::get('/options/vol-signal/{symbol}', [OptionsVolController::class, 'show'])
    ->middleware('throttle:options-vol');
```

In `backend/bootstrap/app.php`, update the comment listing existing per-endpoint limiters to include `options-vol` (accuracy only, no functional change).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=OptionsVolControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AnalyticsServiceClient.php backend/app/Services/DisclosureFormatter.php backend/app/Http/Controllers/OptionsVolController.php backend/routes/api.php backend/app/Providers/AppServiceProvider.php backend/bootstrap/app.php backend/tests/Feature/OptionsVolControllerTest.php
git commit -m "feat(backend): add options vol signal endpoint, client, and disclosure"
```

---

### Task 5: Frontend — standalone Options Vol page

**Files:**
- Create: `frontend/options.html`
- Create: `frontend/src/options.js`
- Modify: `frontend/backtest.html`, `frontend/history.html`, `frontend/strategy-builder.html`, `frontend/journal.html`, `frontend/index.html` (nav links only)

**Interfaces:**
- Consumes: `GET /api/options/vol-signal/{symbol}?asset_class=...` from Task 4.
- Produces: nothing new for later tasks — this is the final task in the plan.

- [ ] **Step 1: Create `options.html`**

New page matching `backtest.html`'s exact CSS variables and card layout — symbol input, asset-class select, a "Check vol signal" button, and a results card with a metrics grid (spot, realized vol, vol rank, vol regime, skew, skew regime) plus a disclosure box reusing the same `.disclosure` styling every other page uses.

- [ ] **Step 2: Create `frontend/src/options.js`**

```javascript
const API_BASE = 'http://localhost:8000/api';

const checkButton = document.getElementById('checkButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');
const symbolInput = document.getElementById('symbol');
const assetClassSelect = document.getElementById('assetClass');

checkButton.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  checkButton.disabled = true;
  checkButton.textContent = 'Checking…';

  const symbol = symbolInput.value.trim();
  const assetClass = assetClassSelect.value;

  try {
    const response = await fetch(
      `${API_BASE}/options/vol-signal/${encodeURIComponent(symbol)}?asset_class=${encodeURIComponent(assetClass)}`,
      { headers: { Accept: 'application/json' } },
    );
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Could not read the options vol signal');
    }

    renderVolSignal(body.result);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    checkButton.disabled = false;
    checkButton.textContent = 'Check vol signal';
  }
});

function renderVolSignal(result) {
  document.getElementById('resultTitle').textContent = `${result.symbol} — options vol`;
  document.getElementById('mSpot').textContent = result.spot.toFixed(2);
  document.getElementById('mRealizedVol').textContent = `${result.realized_vol.current_annualized_pct.toFixed(1)}%`;
  document.getElementById('mVolRank').textContent = `${result.realized_vol.rank_pct.toFixed(0)}th pctile`;

  const volRegimeEl = document.getElementById('mVolRegime');
  volRegimeEl.textContent = result.vol_regime;
  volRegimeEl.className = `value regime-${result.vol_regime}`;

  document.getElementById('mSkew').textContent = result.skew.skew.toFixed(4);

  const skewRegimeEl = document.getElementById('mSkewRegime');
  skewRegimeEl.textContent = result.skew_regime;
  skewRegimeEl.className = `value regime-${result.skew_regime}`;

  const d = result.disclosure;
  document.getElementById('dAttribution').textContent = d.attribution;
  document.getElementById('dRisk').textContent = d.risk_disclosure;

  document.getElementById('results').style.display = 'block';
}
```

- [ ] **Step 3: Add an "Options Vol" nav link to every other page**

`backtest.html`, `history.html`, `strategy-builder.html`, `journal.html` each get one new `<a href="/options.html" ...>Options Vol</a>` link, matching that page's existing nav-link markup exactly (same inline style, same position in the float:right chain). `index.html`'s centered nav gets the same treatment, matching its own existing link markup.

- [ ] **Step 4: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/options.html`, enter a real symbol (e.g. `AAPL`), click "Check vol signal", and confirm it renders real spot/realized-vol/skew numbers with no console errors — this hits the real live `yfinance` options chain, not a mock, so expect a few seconds of latency.
3. Confirm the disclosure text mentions "proxy for true IV rank."
4. Click through from `backtest.html`, `history.html`, `strategy-builder.html`, and `journal.html` to confirm the new nav link works from each.
5. Stop the dev servers.

- [ ] **Step 5: Commit**

```bash
git add frontend/options.html frontend/src/options.js frontend/backtest.html frontend/history.html frontend/strategy-builder.html frontend/journal.html frontend/index.html
git commit -m "feat(frontend): add standalone Options Vol page"
```
