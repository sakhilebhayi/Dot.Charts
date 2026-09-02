"""OCR-based ticker detection for uploaded chart screenshots.

The OCR engine (RapidOCR, ONNX) is imported lazily: it ships its models in
the wheel, needs no system binaries, and only occupies memory once the
first screenshot arrives. Candidate extraction is pure logic, testable
without the engine.

Chart titles rarely contain data-provider symbols verbatim, so recognized
instrument names map to fetchable tickers first (GOLD -> the COMEX future,
not Barrick Gold stock), and obvious chart-furniture words are filtered
out. The caller tries candidates in order against real market data - a
wrong guess fails the fetch and the next candidate gets its turn.
"""
from __future__ import annotations

import base64
import re

# Instrument names / broker codes -> data-provider tickers. Checked before
# the generic token scan, in text order of first appearance.
KNOWN_MAP = {
    "XAUUSD": "GC=F", "XAU": "GC=F", "GOLD": "GC=F",
    "XAGUSD": "SI=F", "XAG": "SI=F", "SILVER": "SI=F",
    "USOIL": "CL=F", "WTI": "CL=F", "CRUDE": "CL=F", "UKOIL": "BZ=F",
    "BTCUSD": "BTC/USDT", "BTCUSDT": "BTC/USDT", "BITCOIN": "BTC/USDT",
    "ETHUSD": "ETH/USDT", "ETHUSDT": "ETH/USDT", "ETHEREUM": "ETH/USDT",
    "US30": "^DJI", "DJI": "^DJI", "DOW": "^DJI",
    "NAS100": "^NDX", "NDX": "^NDX", "US100": "^NDX",
    "SPX": "^GSPC", "SPX500": "^GSPC", "US500": "^GSPC",
    "DXY": "DX-Y.NYB",
    "EURUSD": "EURUSD=X", "GBPUSD": "GBPUSD=X", "USDJPY": "USDJPY=X",
    "AUDUSD": "AUDUSD=X", "USDZAR": "USDZAR=X", "USDCAD": "USDCAD=X",
}

# Chart furniture that matches the ticker shape but never is one.
JUNK = {
    "CFD", "CFDS", "THE", "AND", "FOR", "USD", "EUR", "GBP", "JPY", "ZAR",
    "TVC", "OANDA", "FXCM", "NYSE", "AMEX", "CME", "COMEX", "NYMEX",
    "BUY", "SELL", "HIGH", "LOW", "OPEN", "CLOSE", "VOL", "VOLUME",
    "AM", "PM", "UTC", "GMT", "EST", "SAST", "CHART", "PRICE", "TIME",
    "OZ", "LOT", "PIP", "PIPS", "SL", "TP", "RSI", "EMA", "SMA", "MACD",
    "ATR", "ON", "MON", "TUE", "WED", "THU", "FRI", "SAT", "SUN",
    "JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP",
    "OCT", "NOV", "DEC",
}

_engine = None


class OcrUnavailable(RuntimeError):
    """The OCR engine could not be loaded or run on this host."""


def extract_candidates(text: str, limit: int = 4) -> list[str]:
    """Ordered, de-duplicated ticker candidates from OCR text."""
    upper = text.upper()
    seen: set[str] = set()
    out: list[str] = []

    def add(sym: str) -> None:
        if sym not in seen and len(out) < limit:
            seen.add(sym)
            out.append(sym)

    # 1. Known instruments, in order of first appearance.
    hits = []
    for name, ticker in KNOWN_MAP.items():
        m = re.search(r"\b" + re.escape(name) + r"\b", upper)
        if m:
            hits.append((m.start(), ticker))
    for _, ticker in sorted(hits):
        add(ticker)

    # 2. Exchange-style pairs written with a slash (BTC/USDT, EUR/USD).
    for m in re.finditer(r"\b([A-Z]{2,5})\s*/\s*([A-Z]{2,5})\b", upper):
        base, quote = m.group(1), m.group(2)
        if base in JUNK:
            continue
        if quote in {"USDT", "USDC", "BUSD"}:
            add(f"{base}/{quote}")
        elif quote == "USD" and base in {"BTC", "ETH", "SOL", "XRP", "ADA", "DOGE"}:
            add(f"{base}/USDT")
        elif len(base) == 3 and len(quote) == 3:
            add(f"{base}{quote}=X")

    # 3. Generic uppercase tokens (plain equity tickers like AAPL, GLD).
    for m in re.finditer(r"\b([A-Z]{2,5})\b", upper):
        tok = m.group(1)
        if tok not in JUNK and tok not in KNOWN_MAP:
            add(tok)

    return out


def run_ocr_symbol(image_b64: str) -> dict:
    """Decode a base64 screenshot, OCR it, and return ticker candidates."""
    global _engine
    payload = re.sub(r"^data:image/\w+;base64,", "", image_b64, flags=re.I)
    try:
        raw = base64.b64decode(payload, validate=True)
    except Exception as exc:
        raise OcrUnavailable(f"image is not valid base64: {exc}") from exc
    if not raw:
        raise OcrUnavailable("empty image payload")

    if _engine is None:
        try:
            from rapidocr_onnxruntime import RapidOCR
            _engine = RapidOCR()
        except Exception as exc:  # missing wheel, memory, ONNX failure
            raise OcrUnavailable(f"OCR engine unavailable: {exc}") from exc

    try:
        result, _ = _engine(raw)
    except Exception as exc:
        raise OcrUnavailable(f"OCR inference failed: {exc}") from exc

    lines = [item[1] for item in (result or []) if len(item) > 1]
    text = "\n".join(lines)
    return {"text": text, "candidates": extract_candidates(text)}
