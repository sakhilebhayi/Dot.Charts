from fastapi import FastAPI, HTTPException

from schemas import BacktestRequest, BacktestResult
from data.fetch import fetch_ohlcv, DataFetchError
from strategies import STRATEGY_REGISTRY
from engines.vectorbt_engine import run_vectorbt
from engines.backtrader_engine import run_backtrader

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
        df = fetch_ohlcv(
            request.symbol,
            request.asset_class,
            request.start_date,
            request.end_date,
            interval=entry["interval"],
        )
    except DataFetchError as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    if entry["engine"] == "vectorbt":
        result = run_vectorbt(entry["module"], df, params)
    else:
        result = run_backtrader(entry["strategy_cls"], df, params)

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
