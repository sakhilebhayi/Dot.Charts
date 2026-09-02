"""Candidate extraction is pure logic - tested without the OCR engine."""
from analysis.ocr_symbol import extract_candidates


def test_gold_chart_title_maps_to_the_future_not_barrick():
    text = "CFDs on Gold (US$ / OZ) - 30 - TVC  O4,431.6 H4,437.8"
    assert extract_candidates(text)[0] == "GC=F"


def test_crypto_pair_with_slash():
    assert "BTC/USDT" in extract_candidates("BTC/USDT 1h Binance chart")


def test_forex_pair_maps_to_yahoo_suffix():
    got = extract_candidates("EURUSD 15m OANDA")
    assert got[0] == "EURUSD=X"


def test_plain_equity_ticker_survives_junk_filter():
    got = extract_candidates("AAPL 1D NASDAQ  VOL 23M  RSI 61")
    assert "AAPL" in got
    assert "RSI" not in got and "VOL" not in got


def test_chart_furniture_alone_yields_nothing():
    assert extract_candidates("HIGH LOW OPEN CLOSE VOL RSI EMA") == []


def test_candidates_are_ordered_and_capped():
    text = "GOLD SILVER US30 SPX AAPL MSFT"
    got = extract_candidates(text)
    assert got[0] == "GC=F" and len(got) <= 4
