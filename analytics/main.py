import pandas as pd
from fastapi import FastAPI, HTTPException

from schemas import BacktestRequest, BacktestResult, ChartAnalysisRequest, ValidateRuleRequest
from data.cache import fetch_ohlcv_cached
from data.fetch import DataFetchError
from strategies import STRATEGY_REGISTRY
from strategies.custom_rules import evaluate_rule, InvalidStrategyParamsError
from engines.vectorbt_engine import run_vectorbt, run_vectorbt_pairs
from engines.backtrader_engine import run_backtrader
from analysis.chart_analysis import compute_chart_analysis


# This service has no authentication of its own -- deliberately, per a
# 2026-08-10 decision (wiki.md §8), not an oversight. It's an internal
# backend-for-backend service with no browser-facing consumers, so the
# accepted mitigation is network isolation: never bind this to a public
# interface (never run with --host 0.0.0.0), and in any real deployment
# put it behind a firewall/private network reachable only by the Laravel
# backend. This is weaker than an app-level check (it depends on correct
# deployment config, not on anything this code can enforce or test), which
# is why README.md and wiki.md both call it out explicitly rather than
# leaving it implicit. /backtest's date-range cap (data/cache.py) still
# applies regardless of who calls this directly -- that one IS enforced
# here, independent of network config.
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
        df = fetch_ohlcv_cached(
            request.symbol,
            request.asset_class,
            request.start_date,
            request.end_date,
            interval=entry["interval"],
        )
    except DataFetchError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    if entry.get("requires_symbol_b"):
        symbol_b = params.get("symbol_b")
        if not symbol_b:
            raise HTTPException(status_code=422, detail="pairs_trading requires params.symbol_b")
        try:
            df_b = fetch_ohlcv_cached(
                symbol_b,
                request.asset_class,
                request.start_date,
                request.end_date,
                interval=entry["interval"],
            )
        except DataFetchError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
        result = run_vectorbt_pairs(entry["module"], df, df_b, params)
    elif entry["engine"] == "vectorbt":
        try:
            result = run_vectorbt(entry["module"], df, params)
        except InvalidStrategyParamsError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
    else:
        # method_714's SMC/MTF layer needs the request context (symbol,
        # asset_class, date range) to run its own second fetch_ohlcv call
        # for the higher-timeframe dataset — the strategy only otherwise
        # receives the already-fetched base-timeframe DataFrame.
        backtrader_params = {
            **params,
            "symbol": request.symbol,
            "asset_class": request.asset_class,
            "start_date": request.start_date,
            "end_date": request.end_date,
        }
        result = run_backtrader(entry["strategy_cls"], df, backtrader_params)

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


@app.post("/chart-analysis")
def chart_analysis(request: ChartAnalysisRequest):
    try:
        return compute_chart_analysis(request.symbol, request.asset_class, request.interval)
    except DataFetchError as exc:
        raise HTTPException(status_code=422, detail=str(exc))


@app.post("/validate-rule")
def validate_rule(request: ValidateRuleRequest):
    # A small synthetic DataFrame -- no live market-data fetch needed.
    # 250 bars is enough for any indicator length used in practice
    # (the longest built-in default, EMA/SMA/RSI/ATR/Bollinger, all stay
    # well under 250) to resolve without an insufficient-history error.
    idx = pd.date_range("2020-01-01", periods=250, freq="D")
    close = pd.Series([100.0 + (i % 20) * 0.5 for i in range(250)], index=idx)
    synthetic_df = pd.DataFrame({
        "open": close, "high": close + 1, "low": close - 1, "close": close, "volume": 1000,
    })

    entry_rule = request.rules.get("entry")
    exit_rule = request.rules.get("exit")

    if not entry_rule:
        return {"valid": False, "error": "rules must include an 'entry' rule"}
    if not exit_rule:
        return {"valid": False, "error": "rules must include an 'exit' rule"}

    try:
        evaluate_rule(synthetic_df, entry_rule)
        evaluate_rule(synthetic_df, exit_rule)
    except InvalidStrategyParamsError as exc:
        return {"valid": False, "error": str(exc)}

    return {"valid": True}
