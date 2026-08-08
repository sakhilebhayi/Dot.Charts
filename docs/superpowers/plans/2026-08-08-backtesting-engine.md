# Real Backtesting Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ChartSense's hardcoded demo signal payload with a real backtesting slice: a Python analytics microservice computing real backtests (MA crossover, RSI mean-reversion, 714 Method) against real historical data, called from a new Laravel endpoint that persists runs and renders compliance disclosures.

**Architecture:** New `analytics/` FastAPI service (Python) does all quant computation — vectorbt for the two vectorized presets, backtrader for the stateful, session-based 714 Method — and returns raw JSON. Laravel owns `POST /api/backtests`: validates input, calls the Python service synchronously over HTTP, persists the run to a new `backtest_runs` table, and wraps the result in a disclosure block (confidence band, attribution, loss-honesty) before it reaches the frontend. A new standalone frontend page drives it; the existing OCR chart-upload flow is untouched.

**Tech Stack:** Python 3.11+, FastAPI, pandas, yfinance, ccxt, pandas-ta, vectorbt, backtrader, quantstats, pytest — Laravel 12 (PHP 8.2), PHPUnit — Vite/vanilla JS frontend.

## Global Constraints

- The Python service is stateless and called synchronously over HTTP from Laravel — no queue in this slice (per design doc, revisit only if runtimes grow).
- Two backtesting engines: `vectorbt` for `ma_crossover` and `rsi_mean_reversion`; `backtrader` for `method_714` (session-arming, retest windows, and ratcheting stops are inherently stateful).
- Data sources: `yfinance` for `asset_class: equity`, `ccxt`/Binance for `asset_class: crypto` — free/keyless only, no paid vendor.
- Every backtest result returned to a client must carry a `disclosure` block with `confidence_band`, `attribution`, `risk_disclosure`, `max_drawdown_pct`, and `losing_trade_count` — these loss-honesty fields are never optional, per the compliance posture in `wiki.md` §7.
- New standalone endpoint `POST /api/backtests`. The existing `POST /api/chart/analyze` (OCR demo flow) is not modified.
- Every run is persisted to `backtest_runs` with `status` of `queued` → `complete` or `failed` — never silently dropped.
- No live-network calls in any automated test. Python tests mock `fetch_ohlcv`; Laravel tests mock the outbound HTTP call via `Http::fake`.
- The 714 Method preset in this plan covers only: sessions, retest-continuation/contrarian/momentum signal, EMA/ATR/volume/HTF filters, ATR-based risk management (SL/TP, breakeven, trailing stop, session-flat, one-trade-at-a-time). The SMC engine (BOS/CHoCH, order blocks, FVGs, liquidity sweeps) and weighted confidence scoring are **out of scope** for this plan.
- The app currently has no authentication UI or wired auth middleware (per `wiki.md`) — `backtest_runs.user_id` is nullable and the controller uses `$request->user()?->id`, not a hard auth requirement. This is a deliberate scope boundary, not an oversight.
- Attribution text for `method_714` must state it is an original session-based implementation (ported from a supplied MPL-2.0 Pine Script, © Quant/Infodot), not a verified reproduction of Mashaya A. Mthethwa's proprietary 714 course material.

---

## File Structure

```
ChartSense/
├── analytics/                              # NEW — Python FastAPI service
│   ├── requirements.txt
│   ├── pytest.ini
│   ├── main.py                             # FastAPI app: /health, /backtest
│   ├── schemas.py                          # Pydantic request/response models
│   ├── data/
│   │   ├── __init__.py
│   │   └── fetch.py                        # fetch_ohlcv() — yfinance + ccxt
│   ├── strategies/
│   │   ├── __init__.py                     # STRATEGY_REGISTRY
│   │   ├── ma_crossover.py
│   │   ├── rsi_mean_reversion.py
│   │   └── method_714/
│   │       ├── __init__.py
│   │       ├── sessions.py                 # session engine
│   │       ├── retest.py                   # retest/contrarian/momentum signal
│   │       └── strategy.py                 # backtrader Strategy subclass
│   ├── engines/
│   │   ├── __init__.py
│   │   ├── vectorbt_engine.py
│   │   └── backtrader_engine.py
│   ├── metrics.py                          # quantstats-based metrics, shared by both engines
│   └── tests/
│       ├── conftest.py
│       ├── test_main.py
│       ├── test_fetch.py
│       ├── test_ma_crossover.py
│       ├── test_rsi_mean_reversion.py
│       ├── test_sessions.py
│       ├── test_retest.py
│       ├── test_method_714_engine.py
│       └── test_backtest_endpoint.py
├── backend/
│   ├── app/Models/BacktestRun.php          # NEW
│   ├── app/Services/AnalyticsServiceClient.php  # NEW
│   ├── app/Services/DisclosureFormatter.php     # NEW
│   ├── app/Http/Controllers/BacktestController.php  # NEW
│   ├── config/services.php                 # MODIFY — add 'analytics' block
│   ├── .env.example                        # MODIFY — add ANALYTICS_SERVICE_URL
│   ├── routes/api.php                      # MODIFY — add POST /backtests
│   ├── database/migrations/2026_08_08_000000_create_backtest_runs_table.php  # NEW
│   └── tests/
│       ├── Unit/DisclosureFormatterTest.php     # NEW
│       ├── Unit/AnalyticsServiceClientTest.php  # NEW
│       └── Feature/BacktestControllerTest.php   # NEW
└── frontend/
    ├── backtest.html                       # NEW
    ├── src/backtest.js                     # NEW
    └── vite.config.js                      # MODIFY — multi-page build input
```

---

### Task 1: Python service scaffolding + health check

**Files:**
- Create: `analytics/requirements.txt`
- Create: `analytics/pytest.ini`
- Create: `analytics/main.py`
- Test: `analytics/tests/test_main.py`

**Interfaces:**
- Produces: `main.py` exports `app` (a `fastapi.FastAPI` instance) — every later task that adds a route imports `from main import app`.

- [ ] **Step 1: Create the service scaffolding**

```
analytics/requirements.txt
```
```
fastapi>=0.110
uvicorn[standard]>=0.29
httpx>=0.27
pandas>=2.2
numpy>=1.26
yfinance>=0.2.40
ccxt>=4.3
pandas-ta>=0.3.14b0
vectorbt>=0.26
backtrader>=1.9.78.123
quantstats>=0.0.62
pytest>=8.0
pytest-mock>=3.14
```

```
analytics/pytest.ini
```
```ini
[pytest]
testpaths = tests
pythonpath = .
```

- [ ] **Step 2: Write the failing test**

```python
# analytics/tests/test_main.py
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def test_health_returns_ok():
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd analytics && python -m venv .venv && source .venv/bin/activate && pip install -r requirements.txt && pytest tests/test_main.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'main'` or import error — `main.py` doesn't exist yet)

- [ ] **Step 4: Write minimal implementation**

```python
# analytics/main.py
from fastapi import FastAPI

app = FastAPI(title="Dot.Charts Analytics Service")


@app.get("/health")
def health():
    return {"status": "ok"}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `pytest tests/test_main.py -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/requirements.txt analytics/pytest.ini analytics/main.py analytics/tests/test_main.py
git commit -m "feat(analytics): scaffold FastAPI analytics service with health check"
```

---

### Task 2: OHLCV data fetch layer

**Files:**
- Create: `analytics/data/__init__.py` (empty)
- Create: `analytics/data/fetch.py`
- Test: `analytics/tests/test_fetch.py`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `fetch_ohlcv(symbol: str, asset_class: str, start_date: str, end_date: str, interval: str = "1d") -> pandas.DataFrame` with columns `["open", "high", "low", "close", "volume"]` and a `DatetimeIndex`. Raises `DataFetchError` (also exported from this module) on no data / unsupported `asset_class`. Used by Tasks 5 and 8.

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_fetch.py
import pandas as pd
import pytest
from data.fetch import fetch_ohlcv, DataFetchError


def _fake_yf_download(*args, **kwargs):
    idx = pd.date_range("2023-01-01", periods=5, freq="D")
    return pd.DataFrame(
        {
            "Open": [100, 101, 102, 103, 104],
            "High": [101, 102, 103, 104, 105],
            "Low": [99, 100, 101, 102, 103],
            "Close": [100.5, 101.5, 102.5, 103.5, 104.5],
            "Volume": [1000, 1100, 1200, 1300, 1400],
        },
        index=idx,
    )


def test_fetch_ohlcv_equity_returns_normalized_columns(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("AAPL", "equity", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5


def test_fetch_ohlcv_equity_raises_on_empty_result(mocker):
    mocker.patch("data.fetch.yf.download", return_value=pd.DataFrame())

    with pytest.raises(DataFetchError):
        fetch_ohlcv("BADSYMBOL", "equity", "2023-01-01", "2023-01-05")


def test_fetch_ohlcv_crypto_returns_normalized_columns(mocker):
    fake_exchange = mocker.Mock()
    fake_exchange.parse8601 = lambda s: 0 if "01-01" in s else 5 * 86_400_000
    fake_exchange.fetch_ohlcv.side_effect = [
        [
            [i * 86_400_000, 100 + i, 101 + i, 99 + i, 100.5 + i, 1000 + i]
            for i in range(5)
        ],
        [],
    ]
    mocker.patch("data.fetch.ccxt.binance", return_value=fake_exchange)

    df = fetch_ohlcv("BTC/USDT", "crypto", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5


def test_fetch_ohlcv_unsupported_asset_class_raises():
    with pytest.raises(DataFetchError):
        fetch_ohlcv("AAPL", "commodity", "2023-01-01", "2023-01-05")
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_fetch.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'data.fetch'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/data/fetch.py
import pandas as pd
import yfinance as yf
import ccxt


class DataFetchError(Exception):
    pass


_OHLCV_COLUMNS = ["open", "high", "low", "close", "volume"]


def fetch_ohlcv(
    symbol: str,
    asset_class: str,
    start_date: str,
    end_date: str,
    interval: str = "1d",
) -> pd.DataFrame:
    if asset_class == "equity":
        return _fetch_equity(symbol, start_date, end_date, interval)
    if asset_class == "crypto":
        return _fetch_crypto(symbol, start_date, end_date, interval)
    raise DataFetchError(f"Unsupported asset_class: {asset_class}")


def _fetch_equity(symbol: str, start_date: str, end_date: str, interval: str) -> pd.DataFrame:
    df = yf.download(symbol, start=start_date, end=end_date, interval=interval, progress=False)
    if df is None or df.empty:
        raise DataFetchError(f"No equity data for symbol '{symbol}'")
    df = df.rename(columns=str.lower)
    return df[_OHLCV_COLUMNS]


def _fetch_crypto(symbol: str, start_date: str, end_date: str, interval: str) -> pd.DataFrame:
    exchange = ccxt.binance()
    timeframe = interval if interval in ("1d", "1h", "4h", "15m") else "1d"
    since = exchange.parse8601(f"{start_date}T00:00:00Z")
    end_ms = exchange.parse8601(f"{end_date}T00:00:00Z")

    rows = []
    while since < end_ms:
        batch = exchange.fetch_ohlcv(symbol, timeframe=timeframe, since=since, limit=1000)
        if not batch:
            break
        rows.extend(batch)
        since = batch[-1][0] + 1

    if not rows:
        raise DataFetchError(f"No crypto data for symbol '{symbol}'")

    df = pd.DataFrame(rows, columns=["timestamp", "open", "high", "low", "close", "volume"])
    df["timestamp"] = pd.to_datetime(df["timestamp"], unit="ms")
    df = df.set_index("timestamp")
    return df[_OHLCV_COLUMNS]
```

```python
# analytics/data/__init__.py
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_fetch.py -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/ analytics/tests/test_fetch.py
git commit -m "feat(analytics): add OHLCV data fetch layer (yfinance + ccxt)"
```

---

### Task 3: MA crossover strategy + vectorbt engine + metrics

**Files:**
- Create: `analytics/strategies/__init__.py` (registry stub for now — Task 5 fills it in)
- Create: `analytics/strategies/ma_crossover.py`
- Create: `analytics/engines/__init__.py` (empty)
- Create: `analytics/engines/vectorbt_engine.py`
- Create: `analytics/metrics.py`
- Test: `analytics/tests/test_ma_crossover.py`

**Interfaces:**
- Consumes: nothing (works on a raw OHLCV `DataFrame`, independent of `fetch_ohlcv`).
- Produces:
  - `ma_crossover.DEFAULT_PARAMS: dict`
  - `ma_crossover.generate_signals(df, params) -> tuple[pd.Series, pd.Series]` (entries, exits)
  - `ma_crossover.run(df, params) -> vectorbt.Portfolio`
  - `vectorbt_engine.run_vectorbt(strategy_module, df, params) -> dict` with shape `{"metrics": {...}, "equity_curve": [...], "trades": [...]}` — used by Task 4 and Task 5.
  - `metrics.compute_metrics_from_portfolio(portfolio) -> dict` (same shape) — used by Task 4.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_ma_crossover.py
import pandas as pd
from strategies.ma_crossover import generate_signals, DEFAULT_PARAMS


def _trending_price_series() -> pd.DataFrame:
    # Flat for 60 bars, then a clean uptrend for 40 bars — guarantees a
    # fast/slow SMA crossover partway through, deterministic and mock-free.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_crossover():
    df = _trending_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.any(), "expected at least one entry signal during the uptrend"
    assert entries.sum() >= 1
    # No entries during the flat section where fast == slow MA
    assert not entries.iloc[:60].any()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_ma_crossover.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'strategies'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/__init__.py
# STRATEGY_REGISTRY is populated in full once every strategy exists — see Task 5.
```

```python
# analytics/strategies/ma_crossover.py
import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"fast_window": 20, "slow_window": 50}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    fast_window = params.get("fast_window", DEFAULT_PARAMS["fast_window"])
    slow_window = params.get("slow_window", DEFAULT_PARAMS["slow_window"])

    fast_ma = ta.sma(df["close"], length=fast_window)
    slow_ma = ta.sma(df["close"], length=slow_window)

    entries = (fast_ma > slow_ma) & (fast_ma.shift(1) <= slow_ma.shift(1))
    exits = (fast_ma < slow_ma) & (fast_ma.shift(1) >= slow_ma.shift(1))

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

```python
# analytics/engines/__init__.py
```

```python
# analytics/engines/vectorbt_engine.py
import pandas as pd
from metrics import compute_metrics_from_portfolio


def run_vectorbt(strategy_module, df: pd.DataFrame, params: dict) -> dict:
    portfolio = strategy_module.run(df, params)
    return compute_metrics_from_portfolio(portfolio)
```

```python
# analytics/metrics.py
import pandas as pd
import quantstats as qs


def compute_metrics_from_portfolio(portfolio) -> dict:
    returns = portfolio.returns()
    equity_curve = portfolio.value()
    trades_df = portfolio.trades.records_readable

    trade_count = len(trades_df)
    losing_trade_count = int((trades_df["PnL"] < 0).sum()) if trade_count else 0
    win_rate_pct = float((trades_df["PnL"] > 0).mean() * 100) if trade_count else 0.0
    total_return_pct = float(qs.stats.comp(returns) * 100) if len(returns) else 0.0
    max_drawdown_pct = float(qs.stats.max_drawdown(returns) * 100) if len(returns) else 0.0
    sharpe_ratio = float(qs.stats.sharpe(returns)) if len(returns) > 1 else None

    equity_curve_records = [
        {"time": str(ts), "equity": float(v)} for ts, v in equity_curve.items()
    ]

    trades = [
        {
            "entry_time": str(row["Entry Timestamp"]),
            "exit_time": str(row["Exit Timestamp"]) if pd.notna(row["Exit Timestamp"]) else None,
            "direction": "long" if row["Direction"] == "Long" else "short",
            "entry_price": float(row["Avg Entry Price"]),
            "exit_price": float(row["Avg Exit Price"]) if pd.notna(row["Avg Exit Price"]) else None,
            "pnl": float(row["PnL"]) if pd.notna(row["PnL"]) else None,
        }
        for _, row in trades_df.iterrows()
    ]

    return {
        "metrics": {
            "total_return_pct": total_return_pct,
            "win_rate_pct": win_rate_pct,
            "max_drawdown_pct": max_drawdown_pct,
            "sharpe_ratio": sharpe_ratio,
            "trade_count": trade_count,
            "losing_trade_count": losing_trade_count,
        },
        "equity_curve": equity_curve_records,
        "trades": trades,
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_ma_crossover.py -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/ analytics/engines/ analytics/metrics.py analytics/tests/test_ma_crossover.py
git commit -m "feat(analytics): add MA crossover strategy, vectorbt engine, shared metrics"
```

---

### Task 4: RSI mean-reversion strategy

**Files:**
- Create: `analytics/strategies/rsi_mean_reversion.py`
- Test: `analytics/tests/test_rsi_mean_reversion.py`

**Interfaces:**
- Consumes: `engines.vectorbt_engine.run_vectorbt` (Task 3), `metrics.compute_metrics_from_portfolio` (Task 3).
- Produces: `rsi_mean_reversion.DEFAULT_PARAMS: dict`, `rsi_mean_reversion.generate_signals(df, params) -> tuple[pd.Series, pd.Series]`, `rsi_mean_reversion.run(df, params) -> vectorbt.Portfolio` — used by Task 5.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_rsi_mean_reversion.py
import pandas as pd
from strategies.rsi_mean_reversion import generate_signals, DEFAULT_PARAMS


def _oversold_bounce_series() -> pd.DataFrame:
    # Sharp decline (drives RSI below 30) followed by a recovery (drives RSI
    # back above 70) — deterministic entry/exit without mocking data.
    idx = pd.date_range("2023-01-01", periods=60, freq="D")
    decline = [100.0 - i * 3 for i in range(20)]
    recovery = [100.0 - 19 * 3 + i * 4 for i in range(40)]
    close = pd.Series(decline + recovery, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_after_oversold_and_exit_after_overbought():
    df = _oversold_bounce_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.any(), "expected an entry signal after the RSI dips below 30 and recovers"
    assert exits.any(), "expected an exit signal after the RSI climbs above 70"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_rsi_mean_reversion.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'strategies.rsi_mean_reversion'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/rsi_mean_reversion.py
import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"rsi_length": 14, "oversold": 30, "overbought": 70}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    length = params.get("rsi_length", DEFAULT_PARAMS["rsi_length"])
    oversold = params.get("oversold", DEFAULT_PARAMS["oversold"])
    overbought = params.get("overbought", DEFAULT_PARAMS["overbought"])

    rsi = ta.rsi(df["close"], length=length)

    entries = (rsi < oversold) & (rsi.shift(1) >= oversold)
    exits = (rsi > overbought) & (rsi.shift(1) <= overbought)

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_rsi_mean_reversion.py -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/rsi_mean_reversion.py analytics/tests/test_rsi_mean_reversion.py
git commit -m "feat(analytics): add RSI mean-reversion strategy"
```

---

### Task 5: `POST /backtest` endpoint wired to the two vectorbt presets

**Files:**
- Modify: `analytics/strategies/__init__.py`
- Create: `analytics/schemas.py`
- Modify: `analytics/main.py`
- Test: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `data.fetch.fetch_ohlcv` / `DataFetchError` (Task 2), `strategies.ma_crossover` (Task 3), `strategies.rsi_mean_reversion` (Task 4), `engines.vectorbt_engine.run_vectorbt` (Task 3).
- Produces: `schemas.BacktestRequest`, `schemas.BacktestResult` (Pydantic models — the wire contract Laravel's `AnalyticsServiceClient`, Task 10, sends/receives). `strategies.STRATEGY_REGISTRY: dict[str, dict]` with keys `"engine"`, and either `"module"` (vectorbt strategies) or `"strategy_cls"` (backtrader strategies, added in Task 8), plus `"default_params"` — consumed by `main.py` and extended by Task 8.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_backtest_endpoint.py
import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _synthetic_uptrend_df():
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_backtest_ma_crossover_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "ma_crossover",
            "params": {"fast_window": 20, "slow_window": 50},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "ma_crossover"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
    assert "losing_trade_count" in body["metrics"]
    assert "equity_curve" in body
    assert "trades" in body


def test_backtest_unknown_strategy_returns_422(mocker):
    mocker.patch("main.fetch_ohlcv", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "not_a_real_strategy",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422


def test_backtest_data_fetch_error_returns_422(mocker):
    from data.fetch import DataFetchError

    mocker.patch("main.fetch_ohlcv", side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"))

    response = client.post(
        "/backtest",
        json={
            "symbol": "BADSYMBOL",
            "asset_class": "equity",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422
    assert "BADSYMBOL" in response.json()["detail"]
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_backtest_endpoint.py -v`
Expected: FAIL (`/backtest` route does not exist — 404, or `schemas` module missing)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/schemas.py
from typing import Literal, Optional
from pydantic import BaseModel, Field

AssetClass = Literal["equity", "crypto"]
StrategyName = Literal["ma_crossover", "rsi_mean_reversion", "method_714"]


class BacktestRequest(BaseModel):
    symbol: str
    asset_class: AssetClass
    strategy: StrategyName
    params: dict = Field(default_factory=dict)
    start_date: str
    end_date: str


class TradeRecord(BaseModel):
    entry_time: str
    exit_time: Optional[str] = None
    direction: Literal["long", "short"]
    entry_price: float
    exit_price: Optional[float] = None
    pnl: Optional[float] = None


class BacktestMetrics(BaseModel):
    total_return_pct: float
    win_rate_pct: float
    max_drawdown_pct: float
    sharpe_ratio: Optional[float] = None
    trade_count: int
    losing_trade_count: int


class EquityPoint(BaseModel):
    time: str
    equity: float


class BacktestResult(BaseModel):
    symbol: str
    asset_class: AssetClass
    strategy: StrategyName
    params: dict
    start_date: str
    end_date: str
    metrics: BacktestMetrics
    equity_curve: list[EquityPoint]
    trades: list[TradeRecord]
```

```python
# analytics/strategies/__init__.py
from . import ma_crossover, rsi_mean_reversion

# method_714 is added to this registry in Task 8.
STRATEGY_REGISTRY = {
    "ma_crossover": {
        "engine": "vectorbt",
        "module": ma_crossover,
        "default_params": ma_crossover.DEFAULT_PARAMS,
    },
    "rsi_mean_reversion": {
        "engine": "vectorbt",
        "module": rsi_mean_reversion,
        "default_params": rsi_mean_reversion.DEFAULT_PARAMS,
    },
}
```

```python
# analytics/main.py
from fastapi import FastAPI, HTTPException

from schemas import BacktestRequest, BacktestResult
from data.fetch import fetch_ohlcv, DataFetchError
from strategies import STRATEGY_REGISTRY
from engines.vectorbt_engine import run_vectorbt

app = FastAPI(title="Dot.Charts Analytics Service")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/backtest", response_model=BacktestResult)
def backtest(request: BacktestRequest):
    entry = STRATEGY_REGISTRY.get(request.strategy)
    if entry is None:
        raise HTTPException(status_code=422, detail=f"Unknown strategy '{request.strategy}'")

    params = {**entry["default_params"], **request.params}

    try:
        df = fetch_ohlcv(request.symbol, request.asset_class, request.start_date, request.end_date)
    except DataFetchError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    if entry["engine"] == "vectorbt":
        result = run_vectorbt(entry["module"], df, params)
    else:
        # backtrader engine is wired in Task 8
        raise HTTPException(status_code=422, detail=f"Engine '{entry['engine']}' not yet wired")

    return BacktestResult(
        symbol=request.symbol,
        asset_class=request.asset_class,
        strategy=request.strategy,
        params=params,
        start_date=request.start_date,
        end_date=request.end_date,
        metrics=result["metrics"],
        equity_curve=result["equity_curve"],
        trades=result["trades"],
    )
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_backtest_endpoint.py -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full Python test suite**

Run: `pytest -v`
Expected: all tests from Tasks 1–5 PASS

- [ ] **Step 6: Commit**

```bash
git add analytics/schemas.py analytics/strategies/__init__.py analytics/main.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(analytics): wire POST /backtest endpoint for vectorbt strategies"
```

---

### Task 6: 714 Method — session engine

**Files:**
- Create: `analytics/strategies/method_714/__init__.py` (empty)
- Create: `analytics/strategies/method_714/sessions.py`
- Test: `analytics/tests/test_sessions.py`

**Interfaces:**
- Consumes: nothing (works on a raw OHLCV `DataFrame`).
- Produces: `sessions.DEFAULT_SESSIONS: list[dict]`, `sessions.DEFAULT_TZ: str`, `sessions.compute_sessions(df, sessions=None, tz=DEFAULT_TZ) -> pd.DataFrame` — returns a copy of `df` with added columns `session_name`, `session_open`, `session_start`, `session_end`. Used by Task 7 and Task 8.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_sessions.py
import pandas as pd
from strategies.method_714.sessions import compute_sessions

# 07:00-08:00 Africa/Johannesburg == 05:00-06:00 UTC (SAST is UTC+2, no DST)
_TEST_SESSIONS = [{"name": "session_1", "start": "07:00", "end": "08:00"}]


def test_compute_sessions_marks_start_end_and_open_price():
    idx = pd.date_range("2023-06-01 04:00", periods=6, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {
            "open": [10, 20, 21, 22, 30, 31],
            "high": [10, 20, 21, 22, 30, 31],
            "low": [10, 20, 21, 22, 30, 31],
            "close": [10, 20, 21, 22, 30, 31],
            "volume": [1] * 6,
        },
        index=idx,
    )
    # idx (UTC): 04:00, 05:00, 06:00, 07:00, 08:00, 09:00
    # SAST:      06:00, 07:00, 08:00, 09:00, 10:00, 11:00
    # Session window 07:00-08:00 SAST covers the 05:00 UTC bar only.

    out = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")

    assert out.loc[idx[1], "session_start"] == True  # noqa: E712
    assert out.loc[idx[1], "session_name"] == "session_1"
    assert out.loc[idx[1], "session_open"] == 20
    assert out.loc[idx[2], "session_end"] == True  # noqa: E712
    assert bool(out.loc[idx[0], "session_start"]) is False
    assert pd.isna(out.loc[idx[0], "session_open"])


def test_compute_sessions_defaults_do_not_raise():
    idx = pd.date_range("2023-06-01", periods=48, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {"open": 1, "high": 1, "low": 1, "close": 1, "volume": 1},
        index=idx,
    )

    out = compute_sessions(df)

    assert "session_name" in out.columns
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_sessions.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'strategies.method_714'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/__init__.py
```

```python
# analytics/strategies/method_714/sessions.py
from zoneinfo import ZoneInfo

import pandas as pd

DEFAULT_SESSIONS = [
    {"name": "session_1", "start": "07:00", "end": "08:00"},
    {"name": "session_2", "start": "13:00", "end": "14:00"},
    {"name": "session_3", "start": "16:00", "end": "17:00"},
]
DEFAULT_TZ = "Africa/Johannesburg"


def compute_sessions(df: pd.DataFrame, sessions: list[dict] | None = None, tz: str = DEFAULT_TZ) -> pd.DataFrame:
    """
    Adds session columns to a copy of `df`, which must have a tz-aware
    DatetimeIndex (UTC or otherwise).

    Added columns:
      - session_name: str | None — which configured session this bar falls in
      - session_open: float | NaN — the open price of the session this bar belongs to
      - session_start: bool — True on the first bar inside a session
      - session_end: bool — True on the first bar AFTER a session ends
    """
    sessions = sessions or DEFAULT_SESSIONS
    local_index = df.index.tz_convert(ZoneInfo(tz))

    out = df.copy()
    out["session_name"] = None
    out["session_open"] = float("nan")
    out["session_start"] = False
    out["session_end"] = False

    for sess in sessions:
        start_t = pd.to_datetime(sess["start"]).time()
        end_t = pd.to_datetime(sess["end"]).time()

        in_session = pd.Series([start_t <= t.time() < end_t for t in local_index], index=df.index)
        session_start = in_session & ~in_session.shift(1, fill_value=False)
        session_end = ~in_session & in_session.shift(1, fill_value=False)

        out.loc[in_session, "session_name"] = sess["name"]
        out.loc[session_start, "session_start"] = True
        out.loc[session_end, "session_end"] = True

        session_open_series = out["open"].where(session_start).ffill().where(in_session)
        out.loc[in_session, "session_open"] = session_open_series[in_session]

    return out
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_sessions.py -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/method_714/ analytics/tests/test_sessions.py
git commit -m "feat(analytics): add 714 Method session engine"
```

---

### Task 7: 714 Method — retest/contrarian/momentum signal

**Files:**
- Create: `analytics/strategies/method_714/retest.py`
- Test: `analytics/tests/test_retest.py`

**Interfaces:**
- Consumes: output shape of `sessions.compute_sessions` (Task 6) — a `DataFrame` with `session_open`, `session_start`, `session_end` columns.
- Produces: `retest.DEFAULT_PARAMS: dict`, `retest.generate_signals(df_with_sessions, atr, params) -> pd.Series` (values `1`/`-1`/`0`, aligned to `df_with_sessions.index`) — used by Task 8.

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_retest.py
import pandas as pd
from strategies.method_714.sessions import compute_sessions
from strategies.method_714.retest import generate_signals, DEFAULT_PARAMS

_TEST_SESSIONS = [{"name": "s1", "start": "07:00", "end": "08:00"}]


def _base_df(closes: list[float]) -> pd.DataFrame:
    idx = pd.date_range("2023-06-01 04:00", periods=len(closes), freq="1h", tz="UTC")
    return pd.DataFrame(
        {"open": closes, "high": [c + 1 for c in closes], "low": [c - 1 for c in closes], "close": closes, "volume": 1},
        index=idx,
    )


def test_contrarian_mode_fires_immediately_at_session_end():
    # UTC 05:00 = SAST 07:00 (session start), UTC 06:00 = SAST 08:00 (session end)
    closes = [10, 20, 15, 15, 15, 15]  # session bar (idx[1]) closes DOWN vs its open
    df = _base_df(closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(sessions_df, atr, {**DEFAULT_PARAMS, "mode": "contrarian"})

    # Session closed down (15 < 20) -> contrarian fires a BUY (1) at session_end
    assert signals.loc[df.index[2]] == 1


def test_momentum_mode_fires_immediately_at_session_end():
    closes = [10, 20, 25, 25, 25, 25]  # session bar closes UP vs its open
    df = _base_df(closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(sessions_df, atr, {**DEFAULT_PARAMS, "mode": "momentum"})

    assert signals.loc[df.index[2]] == 1


def test_retest_continuation_fires_on_touch_and_reject():
    # Session open = 20 (bar 1). Session closes up at 25 (bar 2, session_end).
    # Bar 3: price dips to touch 20 (low <= 20) and closes back above bias
    # side with a decisive rejection (>= open + reject_atr).
    closes = [10, 20, 25, 21]
    df = _base_df(closes)
    df.loc[df.index[3], "low"] = 19  # force a touch of the session_open (20)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(
        sessions_df, atr, {**DEFAULT_PARAMS, "mode": "retest_continuation", "retest_reject_atr": 0.5}
    )

    assert signals.loc[df.index[3]] == 1


def test_retest_continuation_expires_after_max_bars():
    closes = [10, 20, 25] + [25] * 20  # long flat stretch after session_end, no touch
    df = _base_df(closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(
        sessions_df, atr, {**DEFAULT_PARAMS, "mode": "retest_continuation", "retest_max_bars": 2}
    )

    assert (signals == 0).all()
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_retest.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'strategies.method_714.retest'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/retest.py
import pandas as pd

DEFAULT_PARAMS = {
    "mode": "retest_continuation",  # "retest_continuation" | "contrarian" | "momentum"
    "retest_max_bars": 16,
    "retest_reject_atr": 0.15,
    "retest_invalidate_atr": 0.75,
}


def generate_signals(df_with_sessions: pd.DataFrame, atr: pd.Series, params: dict) -> pd.Series:
    """
    Returns a pd.Series aligned to df_with_sessions.index: 1 = long, -1 = short,
    0 = no signal, on the bar the signal fires.
    """
    p = {**DEFAULT_PARAMS, **params}
    mode = p["mode"]
    signals = pd.Series(0, index=df_with_sessions.index)

    if mode in ("contrarian", "momentum"):
        return _immediate_mode_signals(df_with_sessions, mode)

    return _retest_continuation_signals(df_with_sessions, atr, p)


def _immediate_mode_signals(df: pd.DataFrame, mode: str) -> pd.Series:
    signals = pd.Series(0, index=df.index)
    session_end_mask = df["session_end"]

    for idx in df.index[session_end_mask]:
        pos = df.index.get_loc(idx)
        if pos == 0:
            continue
        prior_close = df["close"].iloc[pos - 1]
        session_open = df["session_open"].iloc[pos - 1]
        if pd.isna(prior_close) or pd.isna(session_open):
            continue

        closed_down = prior_close < session_open
        closed_up = prior_close > session_open
        if mode == "contrarian":
            signals.loc[idx] = 1 if closed_down else (-1 if closed_up else 0)
        else:
            signals.loc[idx] = 1 if closed_up else (-1 if closed_down else 0)

    return signals


def _retest_continuation_signals(df: pd.DataFrame, atr: pd.Series, p: dict) -> pd.Series:
    signals = pd.Series(0, index=df.index)

    armed = False
    bias_dir = 0
    bias_open = float("nan")
    bias_end_pos = None

    for i, idx in enumerate(df.index):
        row = df.iloc[i]

        if row["session_start"]:
            armed = False  # any new session cancels a pending setup

        if row["session_end"]:
            if i > 0:
                prior_close = df["close"].iloc[i - 1]
                session_open = df["session_open"].iloc[i - 1]
                if not pd.isna(prior_close) and not pd.isna(session_open) and prior_close != session_open:
                    bias_dir = 1 if prior_close > session_open else -1
                    bias_open = session_open
                    bias_end_pos = i
                    armed = True
            continue

        if not armed or bias_end_pos is None or i <= bias_end_pos:
            continue

        bars_elapsed = i - bias_end_pos
        if bars_elapsed > p["retest_max_bars"]:
            armed = False
            continue

        close = row["close"]
        high = row["high"]
        low = row["low"]
        a = atr.iloc[i]

        invalidated = (
            (bias_dir == 1 and close < bias_open - a * p["retest_invalidate_atr"])
            or (bias_dir == -1 and close > bias_open + a * p["retest_invalidate_atr"])
        )
        if invalidated:
            armed = False
            continue

        touched = (low <= bias_open) if bias_dir == 1 else (high >= bias_open)
        rejected = (
            (bias_dir == 1 and close >= bias_open + a * p["retest_reject_atr"])
            or (bias_dir == -1 and close <= bias_open - a * p["retest_reject_atr"])
        )
        if touched and rejected:
            signals.iloc[i] = bias_dir
            armed = False

    return signals
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_retest.py -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/strategies/method_714/retest.py analytics/tests/test_retest.py
git commit -m "feat(analytics): add 714 Method retest/contrarian/momentum signal engine"
```

---

### Task 8: 714 Method — backtrader strategy, engine, and endpoint wiring

**Files:**
- Create: `analytics/strategies/method_714/strategy.py`
- Modify: `analytics/engines/backtrader_engine.py` (create)
- Modify: `analytics/metrics.py` (add `compute_metrics_from_backtrader_strategy`)
- Modify: `analytics/strategies/__init__.py` (register `method_714`)
- Modify: `analytics/main.py` (wire the `backtrader` branch)
- Test: `analytics/tests/test_method_714_engine.py`

**Interfaces:**
- Consumes: `sessions.compute_sessions` (Task 6), `retest.generate_signals` (Task 7), `strategies.STRATEGY_REGISTRY` (Task 5).
- Produces: `strategy.Method714Strategy` (a `backtrader.Strategy` subclass), `backtrader_engine.run_backtrader(strategy_cls, df, params) -> dict` (same `{"metrics", "equity_curve", "trades"}` shape as `run_vectorbt`). `STRATEGY_REGISTRY["method_714"]` becomes available to `main.py`.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_method_714_engine.py
import pandas as pd
from engines.backtrader_engine import run_backtrader
from strategies.method_714.strategy import Method714Strategy


def _synthetic_session_df():
    # 4 days of hourly bars in UTC, structured so at least one session
    # (07:00-08:00 SAST = 05:00-06:00 UTC) closes decisively up each day,
    # giving momentum mode a clean, repeatable entry to trade.
    idx = pd.date_range("2023-06-01 00:00", periods=96, freq="1h", tz="UTC")
    base = 100.0
    closes = []
    for i in range(96):
        hour = idx[i].hour
        if hour == 5:
            base += 5  # session bar: decisive up move
        else:
            base += 0.1
        closes.append(base)
    df = pd.DataFrame(
        {
            "open": [c - 0.5 for c in closes],
            "high": [c + 1 for c in closes],
            "low": [c - 1 for c in closes],
            "close": closes,
            "volume": [1000] * 96,
        },
        index=idx,
    )
    return df


def test_run_backtrader_method_714_returns_metrics_shape():
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_atr_filter": False,
        "use_volume_filter": False,
        "flatten_at_session_start": True,
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert "metrics" in result
    assert "trade_count" in result["metrics"]
    assert "losing_trade_count" in result["metrics"]
    assert "equity_curve" in result
    assert "trades" in result
    assert len(result["equity_curve"]) > 0
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_method_714_engine.py -v`
Expected: FAIL (`ModuleNotFoundError: No module named 'strategies.method_714.strategy'`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/strategy.py
import pandas as pd
import pandas_ta as ta
import backtrader as bt

from .sessions import compute_sessions, DEFAULT_SESSIONS, DEFAULT_TZ
from .retest import generate_signals


class Method714Strategy(bt.Strategy):
    params = dict(
        sessions=None,
        tz=DEFAULT_TZ,
        mode="retest_continuation",
        retest_max_bars=16,
        retest_reject_atr=0.15,
        retest_invalidate_atr=0.75,
        ema_fast=50,
        ema_slow=200,
        use_ema_filter=True,
        atr_length=14,
        atr_min_mult=0.5,
        use_atr_filter=True,
        use_volume_filter=True,
        volume_sma_length=20,
        volume_mult=1.0,
        sl_atr_mult=1.5,
        tp_atr_mult=3.0,
        use_breakeven=True,
        breakeven_trigger_atr=1.0,
        use_trailing_stop=False,
        trailing_atr_mult=2.0,
        flatten_at_session_start=True,
    )

    def __init__(self):
        self.ema_fast = bt.indicators.EMA(period=self.p.ema_fast)
        self.ema_slow = bt.indicators.EMA(period=self.p.ema_slow)
        self.atr = bt.indicators.ATR(period=self.p.atr_length)
        self.volume_sma = bt.indicators.SMA(self.data.volume, period=self.p.volume_sma_length)

        df = self.data.p.dataname  # the pandas.DataFrame passed into bt.feeds.PandasData
        sessions_df = compute_sessions(df, self.p.sessions or DEFAULT_SESSIONS, self.p.tz)
        atr_series = ta.atr(df["high"], df["low"], df["close"], length=self.p.atr_length)
        retest_params = {
            "mode": self.p.mode,
            "retest_max_bars": self.p.retest_max_bars,
            "retest_reject_atr": self.p.retest_reject_atr,
            "retest_invalidate_atr": self.p.retest_invalidate_atr,
        }
        self._signals = generate_signals(sessions_df, atr_series, retest_params)
        self._session_starts = sessions_df["session_start"]

        self.entry_price = None
        self.entry_atr = None
        self.stop_price = None
        self.take_profit_price = None
        self.trade_log = []
        self.equity_curve = []

    def _trend_ok(self, direction: int) -> bool:
        if not self.p.use_ema_filter:
            return True
        return (direction == 1 and self.ema_fast[0] > self.ema_slow[0]) or (
            direction == -1 and self.ema_fast[0] < self.ema_slow[0]
        )

    def _atr_ok(self) -> bool:
        if not self.p.use_atr_filter:
            return True
        session_range = self.data.high[0] - self.data.low[0]
        return session_range >= self.atr[0] * self.p.atr_min_mult

    def _volume_ok(self) -> bool:
        if not self.p.use_volume_filter:
            return True
        return self.data.volume[0] > self.volume_sma[0] * self.p.volume_mult

    def next(self):
        current_time = self.data.num2date(self.data.datetime[0])
        self.equity_curve.append({"time": current_time.isoformat(), "equity": self.broker.getvalue()})

        if self.p.flatten_at_session_start and self.position:
            is_session_start = bool(self._session_starts.get(current_time, False))
            if is_session_start:
                self.close()
                return

        if self.position:
            self._manage_open_position()
            return

        signal = int(self._signals.get(current_time, 0))
        if signal == 0:
            return
        if not self._trend_ok(signal):
            return
        if not self._atr_ok() or not self._volume_ok():
            return

        atr_value = self.atr[0]
        price = self.data.close[0]
        self.entry_price = price
        self.entry_atr = atr_value
        if signal == 1:
            self.stop_price = price - atr_value * self.p.sl_atr_mult
            self.take_profit_price = price + atr_value * self.p.tp_atr_mult
            self.buy()
        else:
            self.stop_price = price + atr_value * self.p.sl_atr_mult
            self.take_profit_price = price - atr_value * self.p.tp_atr_mult
            self.sell()

    def _manage_open_position(self):
        price = self.data.close[0]
        is_long = self.position.size > 0

        if self.p.use_breakeven and self.entry_price is not None:
            moved_enough = (
                is_long and price >= self.entry_price + self.entry_atr * self.p.breakeven_trigger_atr
            ) or (not is_long and price <= self.entry_price - self.entry_atr * self.p.breakeven_trigger_atr)
            if moved_enough:
                self.stop_price = (
                    max(self.stop_price, self.entry_price) if is_long else min(self.stop_price, self.entry_price)
                )

        if self.p.use_trailing_stop:
            trail = (
                price - self.atr[0] * self.p.trailing_atr_mult
                if is_long
                else price + self.atr[0] * self.p.trailing_atr_mult
            )
            self.stop_price = max(self.stop_price, trail) if is_long else min(self.stop_price, trail)

        hit_stop = (is_long and price <= self.stop_price) or (not is_long and price >= self.stop_price)
        hit_tp = (is_long and price >= self.take_profit_price) or (not is_long and price <= self.take_profit_price)
        if hit_stop or hit_tp:
            self.close()

    def notify_trade(self, trade):
        if trade.isclosed:
            self.trade_log.append(
                {
                    "entry_time": bt.num2date(trade.dtopen).isoformat(),
                    "exit_time": bt.num2date(trade.dtclose).isoformat(),
                    "direction": "long" if trade.long else "short",
                    "entry_price": trade.price,
                    "exit_price": trade.price + (trade.pnl / trade.size if trade.size else 0),
                    "pnl": trade.pnl,
                }
            )
```

```python
# analytics/engines/backtrader_engine.py
import backtrader as bt
import pandas as pd

from metrics import compute_metrics_from_backtrader_strategy


def run_backtrader(strategy_cls, df: pd.DataFrame, params: dict) -> dict:
    cerebro = bt.Cerebro()
    data = bt.feeds.PandasData(dataname=df)
    cerebro.adddata(data)
    cerebro.broker.setcash(10_000)
    cerebro.addstrategy(strategy_cls, **params)
    results = cerebro.run()
    strategy_instance = results[0]
    return compute_metrics_from_backtrader_strategy(strategy_instance)
```

Add to `analytics/metrics.py` (append, do not remove the existing `compute_metrics_from_portfolio`):

```python
def compute_metrics_from_backtrader_strategy(strategy) -> dict:
    trades = strategy.trade_log
    trade_count = len(trades)
    losing_trade_count = sum(1 for t in trades if t["pnl"] < 0)
    win_rate_pct = (sum(1 for t in trades if t["pnl"] > 0) / trade_count * 100) if trade_count else 0.0

    equity_values = [pt["equity"] for pt in strategy.equity_curve]
    equity_series = pd.Series(equity_values)
    returns = equity_series.pct_change().dropna()

    total_return_pct = ((equity_values[-1] / equity_values[0]) - 1) * 100 if len(equity_values) > 1 else 0.0
    max_drawdown_pct = float(qs.stats.max_drawdown(returns) * 100) if len(returns) else 0.0
    sharpe_ratio = float(qs.stats.sharpe(returns)) if len(returns) > 1 else None

    return {
        "metrics": {
            "total_return_pct": total_return_pct,
            "win_rate_pct": win_rate_pct,
            "max_drawdown_pct": max_drawdown_pct,
            "sharpe_ratio": sharpe_ratio,
            "trade_count": trade_count,
            "losing_trade_count": losing_trade_count,
        },
        "equity_curve": strategy.equity_curve,
        "trades": trades,
    }
```

Update `analytics/strategies/__init__.py`:

```python
# analytics/strategies/__init__.py
from . import ma_crossover, rsi_mean_reversion
from .method_714.strategy import Method714Strategy

STRATEGY_REGISTRY = {
    "ma_crossover": {
        "engine": "vectorbt",
        "module": ma_crossover,
        "default_params": ma_crossover.DEFAULT_PARAMS,
    },
    "rsi_mean_reversion": {
        "engine": "vectorbt",
        "module": rsi_mean_reversion,
        "default_params": rsi_mean_reversion.DEFAULT_PARAMS,
    },
    "method_714": {
        "engine": "backtrader",
        "strategy_cls": Method714Strategy,
        "default_params": {},
    },
}
```

Update `analytics/main.py`'s `backtest` handler — replace the `else` branch:

```python
    if entry["engine"] == "vectorbt":
        result = run_vectorbt(entry["module"], df, params)
    else:
        result = run_backtrader(entry["strategy_cls"], df, params)
```

And add the import at the top of `main.py`:

```python
from engines.backtrader_engine import run_backtrader
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_method_714_engine.py -v`
Expected: PASS

- [ ] **Step 5: Run the full Python test suite**

Run: `pytest -v`
Expected: all tests from Tasks 1–8 PASS

- [ ] **Step 6: Commit**

```bash
git add analytics/strategies/method_714/strategy.py analytics/engines/backtrader_engine.py \
        analytics/metrics.py analytics/strategies/__init__.py analytics/main.py \
        analytics/tests/test_method_714_engine.py
git commit -m "feat(analytics): add 714 Method backtrader strategy and wire into /backtest"
```

This completes the Python analytics service. Tasks 9–12 build the Laravel side.

---

### Task 9: `backtest_runs` migration + `BacktestRun` model

**Files:**
- Create: `backend/database/migrations/2026_08_08_000000_create_backtest_runs_table.php`
- Create: `backend/app/Models/BacktestRun.php`
- Test: `backend/tests/Unit/BacktestRunTest.php`

**Interfaces:**
- Produces: `App\Models\BacktestRun` (Eloquent model) with `$fillable` = `user_id, symbol, asset_class, strategy, params, start_date, end_date, status, results, error` and casts `params`/`results` to `array`. Used by Task 12.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/BacktestRunTest.php

namespace Tests\Unit;

use App\Models\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_backtest_run_with_json_params_and_results(): void
    {
        $run = BacktestRun::create([
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'status' => 'queued',
        ]);

        $fresh = BacktestRun::find($run->id);

        $this->assertSame('AAPL', $fresh->symbol);
        $this->assertIsArray($fresh->params);
        $this->assertSame(20, $fresh->params['fast_window']);
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->results);
    }

    public function test_user_id_is_nullable(): void
    {
        $run = BacktestRun::create([
            'symbol' => 'BTC/USDT',
            'asset_class' => 'crypto',
            'strategy' => 'method_714',
            'params' => [],
            'start_date' => '2023-01-01',
            'end_date' => '2023-06-01',
            'status' => 'queued',
        ]);

        $this->assertNull($run->user_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=BacktestRunTest`
Expected: FAIL (table `backtest_runs` does not exist / class `App\Models\BacktestRun` not found)

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/database/migrations/2026_08_08_000000_create_backtest_runs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            // Nullable: the app has no wired authentication yet (see wiki.md);
            // this deliberately does not introduce auth as part of this slice.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('asset_class');
            $table->string('strategy');
            $table->json('params');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('queued'); // queued|complete|failed
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
```

```php
<?php
// backend/app/Models/BacktestRun.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestRun extends Model
{
    protected $fillable = [
        'user_id',
        'symbol',
        'asset_class',
        'strategy',
        'params',
        'start_date',
        'end_date',
        'status',
        'results',
        'error',
    ];

    protected $casts = [
        'params' => 'array',
        'results' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BacktestRunTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/database/migrations/2026_08_08_000000_create_backtest_runs_table.php \
        backend/app/Models/BacktestRun.php backend/tests/Unit/BacktestRunTest.php
git commit -m "feat(backend): add backtest_runs migration and BacktestRun model"
```

---

### Task 10: `AnalyticsServiceClient`

**Files:**
- Create: `backend/app/Services/AnalyticsServiceClient.php`
- Modify: `backend/config/services.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Unit/AnalyticsServiceClientTest.php`

**Interfaces:**
- Consumes: nothing from earlier Laravel tasks.
- Produces: `App\Services\AnalyticsServiceClient::runBacktest(array $payload): array` — throws `RuntimeException` on failure. Consumed by Task 12.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/AnalyticsServiceClientTest.php

namespace Tests\Unit;

use App\Services\AnalyticsServiceClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnalyticsServiceClientTest extends TestCase
{
    public function test_run_backtest_returns_decoded_json_on_success(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['strategy' => 'ma_crossover', 'metrics' => ['trade_count' => 5]], 200),
        ]);

        $client = new AnalyticsServiceClient('http://analytics.test');
        $result = $client->runBacktest(['symbol' => 'AAPL']);

        $this->assertSame('ma_crossover', $result['strategy']);
    }

    public function test_run_backtest_throws_on_error_response(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['detail' => 'No equity data for symbol X'], 422),
        ]);

        $client = new AnalyticsServiceClient('http://analytics.test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No equity data for symbol X');

        $client->runBacktest(['symbol' => 'X']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AnalyticsServiceClientTest`
Expected: FAIL (class `App\Services\AnalyticsServiceClient` not found)

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/app/Services/AnalyticsServiceClient.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnalyticsServiceClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.analytics.url', 'http://localhost:8001');
    }

    /**
     * @param array $payload matches the Python service's BacktestRequest shape
     * @return array the decoded JSON response (BacktestResult shape)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function runBacktest(array $payload): array
    {
        $response = Http::timeout(60)->post("{$this->baseUrl}/backtest", $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('detail') ?? "Analytics service returned HTTP {$response->status()}"
            );
        }

        return $response->json();
    }
}
```

Add to `backend/config/services.php` (inside the returned array, alongside the existing entries):

```php
    'analytics' => [
        'url' => env('ANALYTICS_SERVICE_URL', 'http://localhost:8001'),
    ],
```

Add to `backend/.env.example` (near the other API config):

```
ANALYTICS_SERVICE_URL=http://localhost:8001
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AnalyticsServiceClientTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AnalyticsServiceClient.php backend/config/services.php \
        backend/.env.example backend/tests/Unit/AnalyticsServiceClientTest.php
git commit -m "feat(backend): add AnalyticsServiceClient HTTP client for the analytics service"
```

---

### Task 11: `DisclosureFormatter`

**Files:**
- Create: `backend/app/Services/DisclosureFormatter.php`
- Test: `backend/tests/Unit/DisclosureFormatterTest.php`

**Interfaces:**
- Consumes: nothing from earlier Laravel tasks (operates on a plain array matching the Python `BacktestResult` shape from Task 5/8).
- Produces: `App\Services\DisclosureFormatter::format(array $backtestResult): array` — returns the input array plus a `disclosure` key (`confidence_band`, `attribution`, `risk_disclosure`, `max_drawdown_pct`, `losing_trade_count`). Consumed by Task 12.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Unit/DisclosureFormatterTest.php

namespace Tests\Unit;

use App\Services\DisclosureFormatter;
use Tests\TestCase;

class DisclosureFormatterTest extends TestCase
{
    private function baseResult(int $tradeCount): array
    {
        return [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'metrics' => [
                'total_return_pct' => 12.5,
                'win_rate_pct' => 55.0,
                'max_drawdown_pct' => -8.2,
                'sharpe_ratio' => 1.1,
                'trade_count' => $tradeCount,
                'losing_trade_count' => (int) round($tradeCount * 0.4),
            ],
            'equity_curve' => [],
            'trades' => [],
        ];
    }

    public function test_format_adds_disclosure_block_with_loss_fields_always_present(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $this->assertArrayHasKey('disclosure', $formatted);
        $this->assertArrayHasKey('max_drawdown_pct', $formatted['disclosure']);
        $this->assertArrayHasKey('losing_trade_count', $formatted['disclosure']);
        $this->assertArrayHasKey('risk_disclosure', $formatted['disclosure']);
        $this->assertNotEmpty($formatted['disclosure']['risk_disclosure']);
    }

    public function test_confidence_band_is_low_for_few_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(3));

        $this->assertSame('low', $formatted['disclosure']['confidence_band']);
    }

    public function test_confidence_band_is_medium_for_moderate_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(15));

        $this->assertSame('medium', $formatted['disclosure']['confidence_band']);
    }

    public function test_confidence_band_is_high_for_many_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $this->assertSame('high', $formatted['disclosure']['confidence_band']);
    }

    public function test_attribution_names_strategy_symbol_and_params(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $attribution = $formatted['disclosure']['attribution'];
        $this->assertStringContainsString('MA Crossover', $attribution);
        $this->assertStringContainsString('AAPL', $attribution);
        $this->assertStringContainsString('2023-01-01', $attribution);
    }

    public function test_method_714_attribution_notes_original_implementation(): void
    {
        $result = $this->baseResult(40);
        $result['strategy'] = 'method_714';
        $result['params'] = [];

        $formatted = (new DisclosureFormatter())->format($result);

        $this->assertStringContainsString('714 Method', $formatted['disclosure']['attribution']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DisclosureFormatterTest`
Expected: FAIL (class `App\Services\DisclosureFormatter` not found)

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/app/Services/DisclosureFormatter.php

namespace App\Services;

class DisclosureFormatter
{
    private const RISK_DISCLOSURE = 'Backtested performance does not guarantee future results. '
        . 'All trading involves risk of loss. This is not financial advice.';

    private const MIN_TRADES_FOR_HIGH_CONFIDENCE = 30;
    private const MIN_TRADES_FOR_MEDIUM_CONFIDENCE = 10;

    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
    ];

    /**
     * @param array $backtestResult the Python service's BacktestResult shape
     * @return array the same array plus a 'disclosure' key
     */
    public function format(array $backtestResult): array
    {
        $tradeCount = $backtestResult['metrics']['trade_count'] ?? 0;

        return array_merge($backtestResult, [
            'disclosure' => [
                'confidence_band' => $this->confidenceBand($tradeCount),
                'attribution' => $this->attribution($backtestResult),
                'risk_disclosure' => self::RISK_DISCLOSURE,
                'max_drawdown_pct' => $backtestResult['metrics']['max_drawdown_pct'] ?? null,
                'losing_trade_count' => $backtestResult['metrics']['losing_trade_count'] ?? null,
            ],
        ]);
    }

    private function confidenceBand(int $tradeCount): string
    {
        if ($tradeCount >= self::MIN_TRADES_FOR_HIGH_CONFIDENCE) {
            return 'high';
        }
        if ($tradeCount >= self::MIN_TRADES_FOR_MEDIUM_CONFIDENCE) {
            return 'medium';
        }
        return 'low';
    }

    private function attribution(array $backtestResult): string
    {
        $strategyKey = $backtestResult['strategy'] ?? 'unknown';
        $label = self::STRATEGY_LABELS[$strategyKey] ?? $strategyKey;

        $paramsStr = collect($backtestResult['params'] ?? [])
            ->map(fn ($v, $k) => "{$k}={$v}")
            ->implode(', ');

        $attribution = sprintf(
            '%s (%s), backtested %s to %s on %s',
            $label,
            $paramsStr ?: 'default params',
            $backtestResult['start_date'] ?? '?',
            $backtestResult['end_date'] ?? '?',
            $backtestResult['symbol'] ?? '?'
        );

        if ($strategyKey === 'method_714') {
            $attribution .= '. Original session-based implementation (Blupin/Infodot ORD Session '
                . 'Strategy) — not a verified reproduction of Mashaya A. Mthethwa\'s proprietary '
                . '714 course material.';
        }

        return $attribution;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DisclosureFormatterTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/DisclosureFormatter.php backend/tests/Unit/DisclosureFormatterTest.php
git commit -m "feat(backend): add DisclosureFormatter (confidence band, attribution, loss-honesty)"
```

---

### Task 12: `BacktestController` + route

**Files:**
- Create: `backend/app/Http/Controllers/BacktestController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\BacktestRun` (Task 9), `App\Services\AnalyticsServiceClient::runBacktest` (Task 10), `App\Services\DisclosureFormatter::format` (Task 11).
- Produces: `POST /api/backtests` — the full end-to-end endpoint this plan builds toward. Nothing later in this plan depends on it.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/BacktestControllerTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacktestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_and_returns_disclosed_result(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => ['fast_window' => 20, 'slow_window' => 50],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 12.5,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => -8.2,
                    'sharpe_ratio' => 1.1,
                    'trade_count' => 40,
                    'losing_trade_count' => 18,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.disclosure.confidence_band', 'high');
        $response->assertJsonStructure([
            'backtest_run_id',
            'result' => [
                'disclosure' => ['confidence_band', 'attribution', 'risk_disclosure', 'max_drawdown_pct', 'losing_trade_count'],
            ],
        ]);

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'AAPL',
            'status' => 'complete',
        ]);
    }

    public function test_store_marks_run_failed_when_analytics_service_errors(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['detail' => "No equity data for symbol 'BADSYMBOL'"], 422),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'BADSYMBOL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'BADSYMBOL',
            'status' => 'failed',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_unknown_strategy(): void
    {
        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'not_a_real_strategy',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BacktestControllerTest`
Expected: FAIL (route `POST /api/backtests` returns 404)

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/app/Http/Controllers/BacktestController.php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BacktestController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'asset_class' => 'required|in:equity,crypto',
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714',
            'params' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $run = BacktestRun::create([
            // No auth is wired yet (see wiki.md) — user_id stays null for
            // unauthenticated requests rather than forcing a login here.
            'user_id' => $request->user()?->id,
            'symbol' => $validated['symbol'],
            'asset_class' => $validated['asset_class'],
            'strategy' => $validated['strategy'],
            'params' => $validated['params'] ?? [],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'queued',
        ]);

        try {
            $result = $this->analyticsClient->runBacktest([
                'symbol' => $validated['symbol'],
                'asset_class' => $validated['asset_class'],
                'strategy' => $validated['strategy'],
                'params' => $validated['params'] ?? [],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);
        } catch (RuntimeException $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 503);
        }

        $formatted = $this->disclosureFormatter->format($result);

        $run->update(['status' => 'complete', 'results' => $formatted]);

        return response()->json([
            'success' => true,
            'backtest_run_id' => $run->id,
            'result' => $formatted,
        ]);
    }
}
```

Modify `backend/routes/api.php` (add alongside the existing route):

```php
<?php

use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart']);
Route::post('/backtests', [BacktestController::class, 'store']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=BacktestControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Run the full Laravel test suite**

Run: `php artisan test`
Expected: all tests from Tasks 9–12, plus all pre-existing tests, PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/routes/api.php \
        backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): add POST /api/backtests endpoint"
```

---

### Task 13: Frontend backtest page

**Files:**
- Create: `frontend/backtest.html`
- Create: `frontend/src/backtest.js`
- Modify: `frontend/vite.config.js`

**Interfaces:**
- Consumes: `POST http://localhost:8000/api/backtests` (Task 12) — request/response shapes as built in that task.
- Produces: a standalone page at `/backtest.html`, linked from the existing `index.html` header. No other task depends on this one.

**Note on testing:** this repo has no JavaScript test framework or runner configured (only PHPUnit for the backend). Adding one is out of scope for this slice — consistent with the existing `main.js`/`style.css` frontend, which is also untested. Verification here is manual: run the dev servers and exercise the page.

- [ ] **Step 1: Add a nav link from the existing page**

Modify `frontend/index.html` — add inside `<header>`, right after the `.badge` div:

```html
  <nav style="margin-top:14px">
    <a href="/backtest.html" style="color:var(--accent);text-decoration:none;font-size:15px">
      → Run a real backtest
    </a>
  </nav>
```

- [ ] **Step 2: Create the backtest page**

```html
<!-- frontend/backtest.html -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — Backtest</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <style>
    :root {
      --bg:#020617;--panel:rgba(15,23,42,.7);--border:rgba(148,163,184,.15);
      --text:#e5e7eb;--muted:#94a3b8;--accent:#22d3ee;
      --green:#22c55e;--red:#ef4444;--warn-bg:rgba(250,204,21,.1);--warn-border:rgba(250,204,21,.3)
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
    body{margin:0;min-height:100vh;color:var(--text);background:var(--bg)}
    .container{max-width:920px;margin:0 auto;padding:48px 24px}
    h1{font-size:32px;margin-bottom:8px}
    .back-link{color:var(--accent);text-decoration:none;font-size:14px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px;margin-top:24px}
    label{display:block;font-size:13px;color:var(--muted);margin:14px 0 6px}
    input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:15px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    button{margin-top:22px;padding:14px 22px;background:var(--accent);color:var(--bg);border:none;
      border-radius:10px;font-weight:700;cursor:pointer;font-size:15px}
    button:disabled{opacity:.5;cursor:not-allowed}
    #error{color:var(--red);margin-top:14px;display:none}
    #results{margin-top:24px;display:none}
    .metrics-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:16px}
    .metric-box{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px}
    .metric-box label{margin:0 0 4px}
    .metric-box .value{font-size:20px;font-weight:700}
    .metric-box .value.negative{color:var(--red)}
    .metric-box .value.positive{color:var(--green)}
    #equityCurve{width:100%;height:160px;margin-top:20px;background:#0f172a;border-radius:10px;border:1px solid var(--border)}
    .disclosure{margin-top:20px;padding:16px 18px;border-radius:10px;background:var(--warn-bg);
      border:1px solid var(--warn-border);color:#fde68a;font-size:14px;line-height:1.6}
    .disclosure strong{display:block;margin-bottom:6px;color:#fcd34d}
    #methodParams{display:none}
  </style>
</head>
<body>
<div class="container">
  <a class="back-link" href="/">← Back</a>
  <h1>Run a Backtest</h1>
  <p style="color:var(--muted)">Real historical data, real metrics — MA crossover, RSI mean-reversion, or the 714 Method.</p>

  <div class="card">
    <div class="row">
      <div>
        <label for="symbol">Symbol</label>
        <input id="symbol" placeholder="AAPL or BTC/USDT" value="AAPL" />
      </div>
      <div>
        <label for="assetClass">Asset class</label>
        <select id="assetClass">
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="startDate">Start date</label>
        <input id="startDate" type="date" value="2023-01-01" />
      </div>
      <div>
        <label for="endDate">End date</label>
        <input id="endDate" type="date" value="2026-01-01" />
      </div>
    </div>
    <label for="strategy">Strategy</label>
    <select id="strategy">
      <option value="ma_crossover">MA Crossover</option>
      <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
      <option value="method_714">714 Method</option>
    </select>

    <button id="runButton">Run backtest</button>
    <div id="error"></div>
  </div>

  <div id="results" class="card">
    <h2 id="resultTitle"></h2>
    <div class="metrics-grid">
      <div class="metric-box"><label>Total return</label><div class="value" id="mTotalReturn"></div></div>
      <div class="metric-box"><label>Win rate</label><div class="value" id="mWinRate"></div></div>
      <div class="metric-box"><label>Max drawdown</label><div class="value negative" id="mDrawdown"></div></div>
      <div class="metric-box"><label>Sharpe</label><div class="value" id="mSharpe"></div></div>
      <div class="metric-box"><label>Trades</label><div class="value" id="mTrades"></div></div>
      <div class="metric-box"><label>Losing trades</label><div class="value" id="mLosingTrades"></div></div>
    </div>
    <svg id="equityCurve"></svg>
    <div class="disclosure">
      <strong id="dConfidence"></strong>
      <div id="dAttribution"></div>
      <div id="dRisk" style="margin-top:8px"></div>
    </div>
  </div>
</div>
<script type="module" src="/src/backtest.js"></script>
</body>
</html>
```

- [ ] **Step 3: Create the page logic**

```js
// frontend/src/backtest.js
const API_BASE = 'http://localhost:8000/api';

const runButton = document.getElementById('runButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');

runButton.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  runButton.disabled = true;
  runButton.textContent = 'Running…';

  const payload = {
    symbol: document.getElementById('symbol').value.trim(),
    asset_class: document.getElementById('assetClass').value,
    strategy: document.getElementById('strategy').value,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: {},
  };

  try {
    const response = await fetch(`${API_BASE}/backtests`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Backtest failed');
    }

    renderResult(body.result);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    runButton.disabled = false;
    runButton.textContent = 'Run backtest';
  }
});

function renderResult(result) {
  document.getElementById('resultTitle').textContent = `${result.symbol} — ${result.strategy}`;

  const m = result.metrics;
  document.getElementById('mTotalReturn').textContent = `${m.total_return_pct.toFixed(2)}%`;
  document.getElementById('mWinRate').textContent = `${m.win_rate_pct.toFixed(1)}%`;
  document.getElementById('mDrawdown').textContent = `${m.max_drawdown_pct.toFixed(2)}%`;
  document.getElementById('mSharpe').textContent = m.sharpe_ratio == null ? '—' : m.sharpe_ratio.toFixed(2);
  document.getElementById('mTrades').textContent = m.trade_count;
  document.getElementById('mLosingTrades').textContent = m.losing_trade_count;

  renderEquityCurve(result.equity_curve);

  const d = result.disclosure;
  document.getElementById('dConfidence').textContent = `Confidence: ${d.confidence_band}`;
  document.getElementById('dAttribution').textContent = d.attribution;
  document.getElementById('dRisk').textContent = d.risk_disclosure;

  resultsEl.style.display = 'block';
}

function renderEquityCurve(points) {
  const svg = document.getElementById('equityCurve');
  svg.innerHTML = '';
  if (!points || points.length < 2) return;

  const width = svg.clientWidth || 860;
  const height = 160;
  const values = points.map((p) => p.equity);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const coords = points.map((p, i) => {
    const x = (i / (points.length - 1)) * width;
    const y = height - ((p.equity - min) / range) * height;
    return `${x},${y}`;
  });

  const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  polyline.setAttribute('points', coords.join(' '));
  polyline.setAttribute('fill', 'none');
  polyline.setAttribute('stroke', '#22d3ee');
  polyline.setAttribute('stroke-width', '2');
  svg.appendChild(polyline);
}
```

- [ ] **Step 4: Wire the new page into the Vite build**

```js
// frontend/vite.config.js
import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  server: {
    port: 3000,
    open: true
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    minify: 'esbuild',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        backtest: resolve(__dirname, 'backtest.html'),
      },
    },
  },
});
```

- [ ] **Step 5: Manually verify end to end**

Run in three terminals:
```bash
cd analytics && source .venv/bin/activate && uvicorn main:app --port 8001 --reload
```
```bash
cd backend && php artisan serve
```
```bash
cd frontend && npm run dev
```

Open `http://localhost:3000/backtest.html`, submit the default form (AAPL, equity, MA Crossover, 2023-01-01 to 2026-01-01), and confirm: the button shows "Running…", results render with real (non-hardcoded, non-`is_demo`) metrics, the equity curve draws, and the disclosure block shows a confidence band, attribution string, and risk text. Then switch strategy to "714 Method" and re-run to confirm that path also returns results without a server error.

- [ ] **Step 6: Commit**

```bash
git add frontend/backtest.html frontend/src/backtest.js frontend/vite.config.js frontend/index.html
git commit -m "feat(frontend): add backtest page for the real backtesting engine"
```

---

## Plan Self-Review Notes

- **Spec coverage:** every "In scope" bullet from the design doc maps to a task — Python service (Tasks 1–8), `backtest_runs` persistence (Task 9), disclosure rendering (Task 11), standalone endpoint (Task 12), frontend (Task 13), 714 Method reduced core with `backtrader` (Tasks 6–8). "Out of scope" items (SMC engine, confidence scoring, journal, compliance gate, async execution) are not touched by any task.
- **Consistency check:** `run_vectorbt`/`run_backtrader` both return the same `{"metrics", "equity_curve", "trades"}` shape (Tasks 3, 8), which `main.py`'s `backtest()` handler consumes identically regardless of engine (Task 5, extended in Task 8). `STRATEGY_REGISTRY` keys (`"engine"`, `"module"`/`"strategy_cls"`, `"default_params"`) are used consistently from their introduction in Task 5 through their extension in Task 8. Laravel's `DisclosureFormatter` (Task 11) and `BacktestController` (Task 12) both operate on the same array shape emitted by `main.py`'s `BacktestResult` (Task 5), including the `start_date`/`end_date` fields added specifically so `DisclosureFormatter::attribution()` can use them.
- **No placeholders:** every step has real, complete code — no "add error handling" or "TBD" steps.
