from typing import Literal, Optional
from pydantic import BaseModel, Field

AssetClass = Literal["equity", "crypto", "commodity", "forex"]
StrategyName = Literal[
    "ma_crossover", "rsi_mean_reversion", "breakout", "bollinger_mean_reversion", "method_714",
]


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
    confidence_score: Optional[int] = None
    confidence_breakdown: Optional[dict] = None


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


class ChartAnalysisRequest(BaseModel):
    symbol: str
    asset_class: AssetClass
    interval: str = "1d"
