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
        df = _fetch_equity(symbol, start_date, end_date, interval)
    elif asset_class == "crypto":
        df = _fetch_crypto(symbol, start_date, end_date, interval)
    else:
        raise DataFetchError(f"Unsupported asset_class: {asset_class}")

    # Both yfinance and ccxt return a tz-naive DatetimeIndex. Strategies that
    # do timezone-aware session math (e.g. method_714) require a tz-aware
    # index — tz_convert() raises on a naive one — so every OHLCV frame
    # leaving this module is localized to UTC here, once, regardless of
    # source, rather than leaving each strategy to discover/handle this.
    if df.index.tz is None:
        df.index = df.index.tz_localize("UTC")
    return df


def _fetch_equity(symbol: str, start_date: str, end_date: str, interval: str) -> pd.DataFrame:
    df = yf.download(symbol, start=start_date, end=end_date, interval=interval, progress=False)
    if df is None or df.empty:
        raise DataFetchError(f"No equity data for symbol '{symbol}'")
    # Recent yfinance versions return MultiIndex columns — (field, ticker) —
    # even for a single-symbol download; flatten to the field name before
    # renaming, or df["close"] silently returns a DataFrame instead of a
    # Series and every downstream indicator computation breaks quietly.
    if isinstance(df.columns, pd.MultiIndex):
        df.columns = df.columns.get_level_values(0)
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
