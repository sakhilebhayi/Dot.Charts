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
