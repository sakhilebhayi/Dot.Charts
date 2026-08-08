# 714 Method SMC Engine + Confidence Scoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the SMC engine (swing pivots, BOS/CHoCH, order blocks, fair value gaps, liquidity sweeps), multi-timeframe (4h) confirmation, and the full 10-component weighted confidence score to `method_714`, at parity with the Pine source, with an explainable per-trade breakdown.

**Architecture:** Three new pure-function modules (`smc.py`, `mtf.py`, `confidence.py`) alongside the existing `sessions.py`/`retest.py`, following the same pattern — precomputed once in `Method714Strategy.__init__`, looked up per-bar in `next()` via the same tz-naive-index `.get(current_time, ...)` pattern already established. `min_confidence` and `filter_mode` become new strategy params. A deliberate behavior change: the existing unconditional trend/ATR/volume hard-gates are replaced with confidence-weighted inputs (still hard gates only in `filter_mode="hard_filters"`) — this is required for parity with the source's own two-mode design, not an accident.

**Tech Stack:** Same as the existing `method_714` module — Python, pandas, `pandas_ta`, `backtrader`. No new dependencies.

## Global Constraints

- Full parity with the Pine source's weights: session 30, trend 15, MTF 15, ATR 15, volume 10, structure 10, sweep 5, PA quality 10, CLV 5, prev-day sweep 5 — raw sum 120, capped at 100 via `min(score, 100)`.
- Extension band is a hard gate (`extension_ok`), never a scored component, in both filter modes.
- `filter_mode="confidence_only"` (default): individual filters (trend/ATR/MTF/volume) contribute to the score but never independently veto a trade. `filter_mode="hard_filters"`: trend + ATR + MTF + volume must all additionally pass.
- `min_confidence` default 45, matching the source.
- MTF fetch reuses `data.fetch.fetch_ohlcv` unchanged — no new fetch function.
- Order blocks and fair value gaps are computed and tested (in scope) but are informational — they do not feed the confidence score (matches the Pine source, which only scores structure direction and sweep recency, not OB/FVG presence) and are not wired into the API response this slice (chart visualization is explicitly deferred).
- No live-network calls in tests — MTF fetch is mocked, same pattern as the base fetch mocking already established in `test_method_714_engine.py`.
- The full existing Python test suite must still pass after this slice — any regression from the trend/ATR/volume hard-gate → confidence-weighted change must be fixed, not ignored.

---

## File Structure

```
ChartSense/
└── analytics/
    ├── strategies/method_714/
    │   ├── smc.py                          # NEW — pivots, structure, order blocks, FVGs, sweeps
    │   ├── mtf.py                           # NEW — HTF trend alignment
    │   ├── confidence.py                    # NEW — weighted score, extension gate, PA quality, CLV
    │   └── strategy.py                      # MODIFY — integrate all three
    ├── engines/backtrader_engine.py         # MODIFY — pass symbol/asset_class/dates through
    ├── main.py                              # MODIFY — pass symbol/asset_class/dates to run_backtrader
    ├── schemas.py                           # MODIFY — TradeRecord gains optional confidence fields
    └── tests/
        ├── test_smc.py                      # NEW
        ├── test_mtf.py                      # NEW
        ├── test_confidence.py               # NEW
        └── test_method_714_engine.py        # MODIFY — confidence-gating integration tests
```

---

### Task 1: `smc.py` — swing pivots + structure (BOS/CHoCH)

**Files:**
- Create: `analytics/strategies/method_714/smc.py`
- Test: `analytics/tests/test_smc.py`

**Interfaces:**
- Consumes: nothing new (works on a raw OHLCV DataFrame, same as `sessions.py`).
- Produces: `smc.compute_swing_pivots(df, piv_len=5) -> pd.DataFrame` (adds `swing_high`, `swing_low` columns), `smc.compute_structure(df_with_pivots) -> pd.DataFrame` (adds `structure_dir`, `bos`, `choch`, `bull_break`, `bear_break` columns). Used by Task 3 (sweeps) and Task 6 (strategy integration).

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_smc.py
import pandas as pd
from strategies.method_714.smc import compute_swing_pivots, compute_structure


def _flat_df_with_spike(spike_high_at: int, spike_high_value: float, n: int = 11) -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    highs[spike_high_at] = spike_high_value
    lows = [9.0] * n
    closes = [9.5] * n
    return pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)


def test_compute_swing_pivots_confirms_a_high_piv_len_bars_later():
    df = _flat_df_with_spike(spike_high_at=3, spike_high_value=15.0, n=11)

    out = compute_swing_pivots(df, piv_len=2)

    confirm_idx = df.index[3 + 2]
    assert out.loc[confirm_idx, "swing_high"] == 15.0
    # No pivot recorded anywhere else
    assert (out["swing_high"].dropna() == 15.0).all()
    assert out["swing_high"].notna().sum() == 1


def test_compute_swing_pivots_confirms_a_low_piv_len_bars_later():
    idx = pd.date_range("2023-01-01", periods=11, freq="1h", tz="UTC")
    highs = [10.0] * 11
    lows = [9.0] * 11
    lows[4] = 3.0
    closes = [9.5] * 11
    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)

    out = compute_swing_pivots(df, piv_len=2)

    confirm_idx = idx[4 + 2]
    assert out.loc[confirm_idx, "swing_low"] == 3.0


def test_compute_structure_detects_bullish_bos_then_bearish_choch():
    # Bar 0-4: flat. Bar 3 has a high spike (confirmed pivot at bar 5).
    # Bars 6+: close breaks above the confirmed swing high -> bullish BOS
    # (structure_dir was 0, so this is a BOS, not a CHoCH).
    # Then a low spike gets confirmed and closes break below it -> bearish
    # CHoCH (structure_dir was 1, so breaking down is against prior structure).
    n = 20
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    lows = [9.0] * n
    closes = [9.5] * n

    highs[3] = 15.0  # confirmed at bar 5 (piv_len=2)
    for i in range(6, 9):
        closes[i] = 16.0  # breaks above 15.0 -> bullish BOS somewhere in 6..8

    lows[10] = 3.0  # confirmed at bar 12
    for i in range(13, 16):
        closes[i] = 2.0  # breaks below 3.0 -> bearish CHoCH (structure was bullish)

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    pivots_df = compute_swing_pivots(df, piv_len=2)

    out = compute_structure(pivots_df)

    assert out["bos"].any()
    assert out["choch"].any()
    # The bullish break happens before the bearish one
    first_bos_pos = out.index.get_loc(out[out["bos"]].index[0])
    first_choch_pos = out.index.get_loc(out[out["choch"]].index[0])
    assert first_bos_pos < first_choch_pos
    # After the bearish CHoCH, structure_dir is -1
    assert out.loc[out[out["choch"]].index[0], "structure_dir"] == -1
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd analytics && .venv/bin/pytest tests/test_smc.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'strategies.method_714.smc'`

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/smc.py
import pandas as pd


def compute_swing_pivots(df: pd.DataFrame, piv_len: int = 5) -> pd.DataFrame:
    """
    Adds swing_high/swing_low columns: the pivot price, recorded at the bar
    where it becomes confirmed — piv_len bars after the actual extreme, the
    same confirmation lag as Pine's ta.pivothigh/pivotlow. This is real lag
    inherent to how a pivot is defined (you can't know a bar was a local
    extreme until you've seen piv_len bars on both sides of it), not
    repainting.
    """
    out = df.copy()
    out["swing_high"] = float("nan")
    out["swing_low"] = float("nan")

    highs = df["high"].to_numpy()
    lows = df["low"].to_numpy()
    n = len(df)
    high_col = out.columns.get_loc("swing_high")
    low_col = out.columns.get_loc("swing_low")

    for center in range(piv_len, n - piv_len):
        window = slice(center - piv_len, center + piv_len + 1)
        confirm_idx = center + piv_len
        if highs[center] == highs[window].max():
            out.iat[confirm_idx, high_col] = highs[center]
        if lows[center] == lows[window].min():
            out.iat[confirm_idx, low_col] = lows[center]

    return out


def compute_structure(df_with_pivots: pd.DataFrame) -> pd.DataFrame:
    """
    Tracks a running structure_dir (1 bullish, -1 bearish, 0 undefined),
    flipping on a close crossing the last confirmed swing high (bullish
    break) or swing low (bearish break). A break against the prior
    structure direction is a Change of Character (CHoCH); a break with it
    is a Break of Structure (BOS) — matching the Pine source's
    bullChoch/bullBos/bearChoch/bearBos logic exactly.
    """
    out = df_with_pivots.copy()
    closes = out["close"].to_numpy()
    swing_highs = out["swing_high"].to_numpy()
    swing_lows = out["swing_low"].to_numpy()
    n = len(out)

    structure_dirs = [0] * n
    bos_flags = [False] * n
    choch_flags = [False] * n
    bull_breaks = [False] * n
    bear_breaks = [False] * n

    last_ph = float("nan")
    last_pl = float("nan")
    structure_dir = 0
    prev_close = None

    for i in range(n):
        if not pd.isna(swing_highs[i]):
            last_ph = swing_highs[i]
        if not pd.isna(swing_lows[i]):
            last_pl = swing_lows[i]

        bull_break = (
            not pd.isna(last_ph) and prev_close is not None and prev_close <= last_ph < closes[i]
        )
        bear_break = (
            not pd.isna(last_pl) and prev_close is not None and prev_close >= last_pl > closes[i]
        )

        if bull_break:
            choch_flags[i] = structure_dir == -1
            bos_flags[i] = not choch_flags[i]
            structure_dir = 1
        elif bear_break:
            choch_flags[i] = structure_dir == 1
            bos_flags[i] = not choch_flags[i]
            structure_dir = -1

        bull_breaks[i] = bull_break
        bear_breaks[i] = bear_break
        structure_dirs[i] = structure_dir
        prev_close = closes[i]

    out["structure_dir"] = structure_dirs
    out["bos"] = bos_flags
    out["choch"] = choch_flags
    out["bull_break"] = bull_breaks
    out["bear_break"] = bear_breaks
    return out
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_smc.py -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/smc.py analytics/tests/test_smc.py
git commit -m "feat(analytics): add SMC swing pivots and structure (BOS/CHoCH) detection"
```

---

### Task 2: `smc.py` — order blocks + fair value gaps

**Files:**
- Modify: `analytics/strategies/method_714/smc.py`
- Modify: `analytics/tests/test_smc.py`

**Interfaces:**
- Consumes: `compute_structure`'s output (Task 1) for order blocks; a raw OHLCV DataFrame + an ATR series for FVGs.
- Produces: `smc.compute_order_blocks(df_with_structure, max_count=6) -> list[dict]`, `smc.compute_fair_value_gaps(df, atr, min_atr_mult=0.25, max_count=8) -> list[dict]`. Computed and tested per the design's "in scope" bullet; not consumed by confidence scoring (matches the Pine source) or wired into the API response this slice.

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_smc.py — add these

def test_compute_order_blocks_places_bullish_ob_on_last_down_candle_before_break():
    from strategies.method_714.smc import compute_order_blocks

    n = 10
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    opens = [10.0] * n
    closes = [10.0] * n
    highs = [10.5] * n
    lows = [9.5] * n

    # Bar 3: a down candle (close < open) — the expected order block.
    opens[3], closes[3] = 10.0, 9.0
    highs[3], lows[3] = 10.2, 8.8

    df = pd.DataFrame({"open": opens, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    df["structure_dir"] = 0
    df["bull_break"] = False
    df["bear_break"] = False
    df.iloc[6, df.columns.get_loc("bull_break")] = True  # a bullish break fires at bar 6

    order_blocks = compute_order_blocks(df, max_count=6)

    assert len(order_blocks) == 1
    assert order_blocks[0]["type"] == "bullish"
    assert order_blocks[0]["bar_index"] == 3


def test_compute_fair_value_gaps_detects_a_bullish_gap_above_min_size():
    from strategies.method_714.smc import compute_fair_value_gaps

    idx = pd.date_range("2023-01-01", periods=5, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {
            "open": [10, 10, 10, 10, 10],
            "close": [10, 10, 10, 10, 10],
            "high": [10, 10, 10, 10, 10],
            "low": [10, 10, 12, 10, 10],  # bar 2's low creates a gap vs bar 0's high (10)
            "volume": [1, 1, 1, 1, 1],
        },
        index=idx,
    )
    atr = pd.Series(1.0, index=idx)  # min gap size = 0.25 * 1.0 = 0.25; gap here is 2.0

    fvgs = compute_fair_value_gaps(df, atr, min_atr_mult=0.25)

    assert len(fvgs) == 1
    assert fvgs[0]["type"] == "bullish"
    assert fvgs[0]["bar_index"] == 2
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `.venv/bin/pytest tests/test_smc.py -v -k "order_blocks or fair_value"`
Expected: FAIL — `ImportError: cannot import name 'compute_order_blocks'`

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/smc.py — add these two functions

def compute_order_blocks(df_with_structure: pd.DataFrame, max_count: int = 6) -> list[dict]:
    """
    A bullish order block is the last down-candle (close < open) before a
    bullish structure break; a bearish order block is the last up-candle
    before a bearish break. Returns at most the most recent `max_count`,
    matching the Pine source's bounded rolling list.
    """
    order_blocks = []
    last_down = None
    last_up = None

    opens = df_with_structure["open"].to_numpy()
    closes = df_with_structure["close"].to_numpy()
    highs = df_with_structure["high"].to_numpy()
    lows = df_with_structure["low"].to_numpy()
    bull_breaks = df_with_structure["bull_break"].to_numpy()
    bear_breaks = df_with_structure["bear_break"].to_numpy()

    for i in range(len(df_with_structure)):
        if closes[i] < opens[i]:
            last_down = {"high": highs[i], "low": lows[i], "bar_index": i}
        if closes[i] > opens[i]:
            last_up = {"high": highs[i], "low": lows[i], "bar_index": i}

        if bull_breaks[i] and last_down is not None:
            order_blocks.append({"type": "bullish", **last_down})
        if bear_breaks[i] and last_up is not None:
            order_blocks.append({"type": "bearish", **last_up})

    return order_blocks[-max_count:] if len(order_blocks) > max_count else order_blocks


def compute_fair_value_gaps(
    df: pd.DataFrame, atr: pd.Series, min_atr_mult: float = 0.25, max_count: int = 8
) -> list[dict]:
    """
    A bullish FVG is a 3-bar gap where the current bar's low is above the
    high two bars ago; a bearish FVG mirrors it. Only gaps at least
    min_atr_mult * ATR wide count, matching the Pine source's fvgMinAtr.
    """
    fvgs = []
    highs = df["high"].to_numpy()
    lows = df["low"].to_numpy()
    atr_values = atr.to_numpy()

    for i in range(2, len(df)):
        if pd.isna(atr_values[i]):
            continue
        min_size = atr_values[i] * min_atr_mult

        if lows[i] > highs[i - 2] and (lows[i] - highs[i - 2]) >= min_size:
            fvgs.append({"type": "bullish", "top": lows[i], "bottom": highs[i - 2], "bar_index": i})
        if highs[i] < lows[i - 2] and (lows[i - 2] - highs[i]) >= min_size:
            fvgs.append({"type": "bearish", "top": lows[i - 2], "bottom": highs[i], "bar_index": i})

    return fvgs[-max_count:] if len(fvgs) > max_count else fvgs
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_smc.py -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/smc.py analytics/tests/test_smc.py
git commit -m "feat(analytics): add SMC order blocks and fair value gaps detection"
```

---

### Task 3: `smc.py` — liquidity sweeps (swing-based + previous-day)

**Files:**
- Modify: `analytics/strategies/method_714/smc.py`
- Modify: `analytics/tests/test_smc.py`

**Interfaces:**
- Consumes: `compute_swing_pivots`'s output (Task 1) for swing-based sweeps; a raw OHLCV DataFrame for previous-day sweeps.
- Produces: `smc.compute_liquidity_sweeps(df_with_pivots, lookback_bars=10) -> pd.DataFrame` (adds `sweep_bull`, `sweep_bear`, `recent_bull_sweep`, `recent_bear_sweep`), `smc.compute_prev_day_sweeps(df, tz, lookback_bars=10) -> pd.DataFrame` (adds `prev_day_high`, `prev_day_low`, `recent_pd_bull_sweep`, `recent_pd_bear_sweep`). Used by Task 6 (strategy integration).

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_smc.py — add these

def test_compute_liquidity_sweeps_detects_a_bullish_sweep_and_marks_it_recent():
    from strategies.method_714.smc import compute_liquidity_sweeps

    n = 15
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    lows = [9.0] * n
    closes = [9.5] * n

    lows[4] = 3.0  # confirmed swing low at bar 6 (piv_len=2)

    # Bar 8: wick trades below the swing low (3.0) but closes back above it
    lows[8] = 2.5
    closes[8] = 9.5

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    pivots_df = compute_swing_pivots(df, piv_len=2)

    out = compute_liquidity_sweeps(pivots_df, lookback_bars=3)

    assert out.loc[idx[8], "sweep_bull"] == True  # noqa: E712
    assert out.loc[idx[8], "recent_bull_sweep"] == True  # noqa: E712
    assert out.loc[idx[8 + 3], "recent_bull_sweep"] == True  # noqa: E712
    assert out.loc[idx[8 + 4], "recent_bull_sweep"] == False  # noqa: E712  (outside lookback)


def test_compute_prev_day_sweeps_detects_a_sweep_of_yesterdays_low():
    from strategies.method_714.smc import compute_prev_day_sweeps

    # Day 1: 24 hourly bars, low stays at 9.0. Day 2: bar 3 wicks below 9.0
    # but closes back above it -> a previous-day-low sweep.
    idx = pd.date_range("2023-06-01 00:00", periods=48, freq="1h", tz="UTC")
    highs = [10.0] * 48
    lows = [9.0] * 48
    closes = [9.5] * 48

    lows[27] = 8.0  # day 2, hour 3: wicks below day 1's low (9.0)
    closes[27] = 9.5

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)

    out = compute_prev_day_sweeps(df, tz="UTC", lookback_bars=3)

    assert out.loc[idx[27], "recent_pd_bull_sweep"] == True  # noqa: E712
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `.venv/bin/pytest tests/test_smc.py -v -k "sweep"`
Expected: FAIL — `ImportError: cannot import name 'compute_liquidity_sweeps'`

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/smc.py — add these two functions and the needed imports at the top
```

```python
# analytics/strategies/method_714/smc.py — top of file, add these imports
from datetime import timedelta
from zoneinfo import ZoneInfo

import pandas as pd
```

```python
# analytics/strategies/method_714/smc.py — append

def compute_liquidity_sweeps(df_with_pivots: pd.DataFrame, lookback_bars: int = 10) -> pd.DataFrame:
    """
    A bearish sweep: a wick trades above the last confirmed swing high but
    closes back below it (buy-side liquidity taken). A bullish sweep
    mirrors it at the last swing low. recent_*_sweep stays true for
    lookback_bars after the sweep bar, matching the Pine source's "recent
    memory" pattern so a session signal shortly after a sweep still counts
    it as confluence.
    """
    out = df_with_pivots.copy()
    swing_highs = out["swing_high"].to_numpy()
    swing_lows = out["swing_low"].to_numpy()
    highs = out["high"].to_numpy()
    lows = out["low"].to_numpy()
    closes = out["close"].to_numpy()
    n = len(out)

    sweep_bull = [False] * n
    sweep_bear = [False] * n
    recent_bull = [False] * n
    recent_bear = [False] * n

    last_ph = float("nan")
    last_pl = float("nan")
    last_bull_pos = None
    last_bear_pos = None

    for i in range(n):
        if not pd.isna(swing_highs[i]):
            last_ph = swing_highs[i]
        if not pd.isna(swing_lows[i]):
            last_pl = swing_lows[i]

        sb = not pd.isna(last_pl) and lows[i] < last_pl and closes[i] > last_pl
        se = not pd.isna(last_ph) and highs[i] > last_ph and closes[i] < last_ph

        if sb:
            last_bull_pos = i
        if se:
            last_bear_pos = i

        sweep_bull[i] = sb
        sweep_bear[i] = se
        recent_bull[i] = last_bull_pos is not None and (i - last_bull_pos) <= lookback_bars
        recent_bear[i] = last_bear_pos is not None and (i - last_bear_pos) <= lookback_bars

    out["sweep_bull"] = sweep_bull
    out["sweep_bear"] = sweep_bear
    out["recent_bull_sweep"] = recent_bull
    out["recent_bear_sweep"] = recent_bear
    return out


def compute_prev_day_sweeps(df: pd.DataFrame, tz: str, lookback_bars: int = 10) -> pd.DataFrame:
    """
    A sweep-and-reclaim of the previous calendar day's high/low — the
    liquidity levels most watched at daily scale. recent_pd_*_sweep uses
    the same lookback-window pattern as compute_liquidity_sweeps.
    """
    out = df.copy()
    local_index = out.index.tz_convert(ZoneInfo(tz))
    bar_dates = pd.Series([t.date() for t in local_index], index=out.index)

    daily_high = out["high"].groupby(bar_dates).max()
    daily_low = out["low"].groupby(bar_dates).min()

    prev_day_high = bar_dates.map(lambda d: daily_high.get(d - timedelta(days=1)))
    prev_day_low = bar_dates.map(lambda d: daily_low.get(d - timedelta(days=1)))

    out["prev_day_high"] = prev_day_high.to_numpy()
    out["prev_day_low"] = prev_day_low.to_numpy()

    pd_sweep_bull = (out["low"] < out["prev_day_low"]) & (out["close"] > out["prev_day_low"])
    pd_sweep_bear = (out["high"] > out["prev_day_high"]) & (out["close"] < out["prev_day_high"])

    bull_arr = pd_sweep_bull.fillna(False).to_numpy()
    bear_arr = pd_sweep_bear.fillna(False).to_numpy()
    n = len(out)
    recent_bull = [False] * n
    recent_bear = [False] * n
    last_bull_pos = None
    last_bear_pos = None

    for i in range(n):
        if bull_arr[i]:
            last_bull_pos = i
        if bear_arr[i]:
            last_bear_pos = i
        recent_bull[i] = last_bull_pos is not None and (i - last_bull_pos) <= lookback_bars
        recent_bear[i] = last_bear_pos is not None and (i - last_bear_pos) <= lookback_bars

    out["recent_pd_bull_sweep"] = recent_bull
    out["recent_pd_bear_sweep"] = recent_bear
    return out
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_smc.py -v`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/smc.py analytics/tests/test_smc.py
git commit -m "feat(analytics): add SMC liquidity sweep detection (swing + previous-day)"
```

---

### Task 4: `mtf.py` — multi-timeframe trend confirmation

**Files:**
- Create: `analytics/strategies/method_714/mtf.py`
- Test: `analytics/tests/test_mtf.py`

**Interfaces:**
- Consumes: `data.fetch.fetch_ohlcv` (existing, unchanged).
- Produces: `mtf.compute_htf_trend(symbol, asset_class, start_date, end_date, base_index, htf_interval="4h", fast=50, slow=200) -> pd.Series` (values 1/-1/0, aligned to `base_index`). Used by Task 6 (strategy integration).

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_mtf.py
import pandas as pd
from strategies.method_714.mtf import compute_htf_trend


def _htf_df(closes: list[float]) -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=len(closes), freq="4h", tz="UTC")
    return pd.DataFrame(
        {"open": closes, "high": closes, "low": closes, "close": closes, "volume": 1000}, index=idx
    )


def test_compute_htf_trend_is_non_repainting_and_aligns_to_base_index(mocker):
    # A clean, sustained uptrend so EMA(3) > EMA(6) is unambiguous once
    # warmed up (short periods here so the fixture stays small).
    closes = [100.0 + i * 2 for i in range(30)]
    mocker.patch("strategies.method_714.mtf.fetch_ohlcv", return_value=_htf_df(closes))

    base_index = pd.date_range("2023-01-01", periods=120, freq="1h", tz="UTC")

    trend = compute_htf_trend(
        "AAPL", "equity", "2023-01-01", "2023-01-06", base_index, htf_interval="4h", fast=3, slow=6
    )

    assert len(trend) == len(base_index)
    # Well after warmup, the sustained uptrend should read bullish
    assert trend.iloc[-1] == 1
    # Before any HTF bar has closed, there is nothing to align to yet
    assert trend.iloc[0] in (0, -1, 1)  # no crash; specific early value isn't asserted


def test_compute_htf_trend_reads_bearish_in_a_downtrend(mocker):
    closes = [200.0 - i * 2 for i in range(30)]
    mocker.patch("strategies.method_714.mtf.fetch_ohlcv", return_value=_htf_df(closes))

    base_index = pd.date_range("2023-01-01", periods=120, freq="1h", tz="UTC")

    trend = compute_htf_trend(
        "AAPL", "equity", "2023-01-01", "2023-01-06", base_index, htf_interval="4h", fast=3, slow=6
    )

    assert trend.iloc[-1] == -1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `.venv/bin/pytest tests/test_mtf.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'strategies.method_714.mtf'`

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/mtf.py
import pandas as pd
import pandas_ta as ta

from data.fetch import fetch_ohlcv


def compute_htf_trend(
    symbol: str,
    asset_class: str,
    start_date: str,
    end_date: str,
    base_index: pd.DatetimeIndex,
    htf_interval: str = "4h",
    fast: int = 50,
    slow: int = 200,
) -> pd.Series:
    """
    Fetches a higher-timeframe dataset and returns a trend series (1
    bullish, -1 bearish, 0 flat/insufficient-data) aligned to base_index.

    Non-repainting: the HTF trend is shifted by one HTF bar before
    alignment, so a base-timeframe bar only ever sees the most recently
    fully-closed HTF bar's EMA state — matching the Pine source's
    request.security(..., lookahead=barmerge.lookahead_off) semantics.
    """
    htf_df = fetch_ohlcv(symbol, asset_class, start_date, end_date, interval=htf_interval)

    ema_fast = ta.ema(htf_df["close"], length=fast)
    ema_slow = ta.ema(htf_df["close"], length=slow)

    htf_trend = pd.Series(0, index=htf_df.index)
    htf_trend[ema_fast > ema_slow] = 1
    htf_trend[ema_fast < ema_slow] = -1
    htf_trend = htf_trend.shift(1).fillna(0)

    combined_index = base_index.union(htf_trend.index)
    aligned = htf_trend.reindex(combined_index).ffill().reindex(base_index).fillna(0)
    return aligned.astype(int)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `.venv/bin/pytest tests/test_mtf.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/mtf.py analytics/tests/test_mtf.py
git commit -m "feat(analytics): add multi-timeframe (4h) trend confirmation"
```

---

### Task 5: `confidence.py` — weighted score, extension gate, PA quality, CLV

**Files:**
- Create: `analytics/strategies/method_714/confidence.py`
- Test: `analytics/tests/test_confidence.py`

**Interfaces:**
- Consumes: nothing new — pure functions over booleans/floats already available from the existing strategy filters (Task 6 wires the callers).
- Produces: `confidence.WEIGHTS: dict`, `confidence.compute_confidence(direction, trend_ok, mtf_ok, atr_ok, volume_ok, structure_aligned, sweep_aligned, pa_quality_ok, clv_ok, prev_day_sweep_aligned) -> dict` (`{"score": int, "breakdown": dict}`), `confidence.extension_ok(open_price, close_price, atr_value, min_mult=0.10, max_mult=3.00) -> bool`, `confidence.pa_quality_ok(direction, open_price, high, low, close, mode, body_min=0.50, wick_min=0.33) -> bool`, `confidence.clv_ok(direction, high, low, close, min_pct=25.0) -> bool`. Used by Task 6 (strategy integration).

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_confidence.py
from strategies.method_714.confidence import (
    compute_confidence,
    extension_ok,
    pa_quality_ok,
    clv_ok,
    WEIGHTS,
)


def test_compute_confidence_sums_each_component_by_its_documented_weight():
    result = compute_confidence(
        direction=1,
        trend_ok=True,
        mtf_ok=False,
        atr_ok=True,
        volume_ok=False,
        structure_aligned=True,
        sweep_aligned=False,
        pa_quality_ok=True,
        clv_ok=False,
        prev_day_sweep_aligned=True,
    )

    expected = WEIGHTS["session"] + WEIGHTS["trend"] + WEIGHTS["atr"] + WEIGHTS["structure"] \
        + WEIGHTS["pa_quality"] + WEIGHTS["prev_day_sweep"]
    assert result["score"] == expected
    assert result["breakdown"]["mtf"] == 0
    assert result["breakdown"]["trend"] == WEIGHTS["trend"]


def test_compute_confidence_returns_zero_when_no_direction():
    result = compute_confidence(
        direction=0, trend_ok=True, mtf_ok=True, atr_ok=True, volume_ok=True,
        structure_aligned=True, sweep_aligned=True, pa_quality_ok=True, clv_ok=True,
        prev_day_sweep_aligned=True,
    )

    assert result["score"] == 0


def test_compute_confidence_caps_at_100_even_though_raw_weights_sum_higher():
    result = compute_confidence(
        direction=1, trend_ok=True, mtf_ok=True, atr_ok=True, volume_ok=True,
        structure_aligned=True, sweep_aligned=True, pa_quality_ok=True, clv_ok=True,
        prev_day_sweep_aligned=True,
    )

    raw_sum = sum(WEIGHTS.values())
    assert raw_sum > 100  # the source's own weights sum to 120 before capping
    assert result["score"] == 100


def test_extension_ok_rejects_moves_too_small_or_too_large():
    # ATR = 10; band is [1.0, 30.0]
    assert extension_ok(open_price=100, close_price=100.5, atr_value=10) is False  # 0.5 < min
    assert extension_ok(open_price=100, close_price=105, atr_value=10) is True  # 5 within band
    assert extension_ok(open_price=100, close_price=140, atr_value=10) is False  # 40 > max


def test_pa_quality_ok_momentum_mode_requires_strong_body_in_signal_direction():
    # Strong bullish body: open=100, close=110, range=12 (body=10, body/range=0.83)
    assert pa_quality_ok(direction=1, open_price=100, high=111, low=99, close=110, mode="momentum") is True
    # Weak body (body/range < 0.50)
    assert pa_quality_ok(direction=1, open_price=100, high=120, low=80, close=102, mode="momentum") is False


def test_pa_quality_ok_contrarian_mode_requires_rejection_wick():
    # Bullish bias: strong lower wick (rejection from the low)
    assert pa_quality_ok(direction=1, open_price=100, high=101, low=80, close=100.5, mode="contrarian") is True
    # No meaningful lower wick
    assert pa_quality_ok(direction=1, open_price=100, high=101, low=99.5, close=100.5, mode="contrarian") is False


def test_clv_ok_requires_close_far_enough_from_the_bias_side_extreme():
    # Bullish: close near the high of the range -> good CLV
    assert clv_ok(direction=1, high=110, low=100, close=109, min_pct=25.0) is True
    # Bullish: close near the low of the range -> poor CLV
    assert clv_ok(direction=1, high=110, low=100, close=101, min_pct=25.0) is False
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `.venv/bin/pytest tests/test_confidence.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'strategies.method_714.confidence'`

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/confidence.py

# Weights match the Pine source's f_confidence function exactly. Raw sum is
# 120 (not 100) — the source caps the total at 100 rather than normalizing
# the weights to sum to 100, so a strong-but-imperfect signal (missing one
# or two components) can still reach the cap.
WEIGHTS = {
    "session": 30,
    "trend": 15,
    "mtf": 15,
    "atr": 15,
    "volume": 10,
    "structure": 10,
    "sweep": 5,
    "pa_quality": 10,
    "clv": 5,
    "prev_day_sweep": 5,
}


def compute_confidence(
    direction: int,
    trend_ok: bool,
    mtf_ok: bool,
    atr_ok: bool,
    volume_ok: bool,
    structure_aligned: bool,
    sweep_aligned: bool,
    pa_quality_ok: bool,
    clv_ok: bool,
    prev_day_sweep_aligned: bool,
) -> dict:
    """
    Returns {"score": int, "breakdown": {component: points}} — the
    breakdown exists so a confidence number is never opaque: callers can
    see exactly which components fired and how many points each
    contributed, per the explainability requirement.
    """
    if direction == 0:
        return {"score": 0, "breakdown": {k: 0 for k in WEIGHTS}}

    breakdown = {
        "session": WEIGHTS["session"],
        "trend": WEIGHTS["trend"] if trend_ok else 0,
        "mtf": WEIGHTS["mtf"] if mtf_ok else 0,
        "atr": WEIGHTS["atr"] if atr_ok else 0,
        "volume": WEIGHTS["volume"] if volume_ok else 0,
        "structure": WEIGHTS["structure"] if structure_aligned else 0,
        "sweep": WEIGHTS["sweep"] if sweep_aligned else 0,
        "pa_quality": WEIGHTS["pa_quality"] if pa_quality_ok else 0,
        "clv": WEIGHTS["clv"] if clv_ok else 0,
        "prev_day_sweep": WEIGHTS["prev_day_sweep"] if prev_day_sweep_aligned else 0,
    }
    score = min(sum(breakdown.values()), 100)
    return {"score": score, "breakdown": breakdown}


def extension_ok(
    open_price: float, close_price: float, atr_value: float, min_mult: float = 0.10, max_mult: float = 3.00
) -> bool:
    """
    Hard gate (not scored): the session's |close - open| must be between
    min_mult and max_mult times ATR. Below min = no conviction; above max
    = the move is already exhausted, don't chase it. Enforced in both
    filter modes, matching the Pine source's own "(hard gate)" labeling.
    """
    if atr_value <= 0:
        return False
    extension = abs(close_price - open_price)
    return min_mult * atr_value <= extension <= max_mult * atr_value


def pa_quality_ok(
    direction: int,
    open_price: float,
    high: float,
    low: float,
    close: float,
    mode: str,
    body_min: float = 0.50,
    wick_min: float = 0.33,
) -> bool:
    """
    Momentum mode: the signal-bar candle must have a strong directional
    body (body/range >= body_min) in the signal's direction. Contrarian
    (and retest) modes: the candle must show a rejection wick on the bias
    side (evidence the fade/rejection has already started).
    """
    candle_range = high - low
    if candle_range <= 0:
        return False

    if mode == "momentum":
        correct_direction = (direction == 1 and close > open_price) or (direction == -1 and close < open_price)
        body = abs(close - open_price)
        return correct_direction and (body / candle_range) >= body_min

    lower_wick = min(close, open_price) - low
    upper_wick = high - max(close, open_price)
    if direction == 1:
        return (lower_wick / candle_range) >= wick_min
    return (upper_wick / candle_range) >= wick_min


def clv_ok(direction: int, high: float, low: float, close: float, min_pct: float = 25.0) -> bool:
    """
    Close Location Value: where the bar closed within its own high-low
    range. Longs require the close to be at least min_pct off the low
    (absorption, not free-fall); shorts mirror at the high.
    """
    candle_range = high - low
    if candle_range <= 0:
        return False
    clv_pct = 100.0 * (close - low) / candle_range
    if direction == 1:
        return clv_pct >= min_pct
    return (100 - clv_pct) >= min_pct
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_confidence.py -v`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/confidence.py analytics/tests/test_confidence.py
git commit -m "feat(analytics): add 714 Method weighted confidence score + extension/PA/CLV gates"
```

---

### Task 6: Integrate into `Method714Strategy` + wire MTF fetch through the API

**Files:**
- Modify: `analytics/strategies/method_714/strategy.py`
- Modify: `analytics/engines/backtrader_engine.py`
- Modify: `analytics/main.py`
- Modify: `analytics/schemas.py`
- Modify: `analytics/tests/test_method_714_engine.py`

**Interfaces:**
- Consumes: `smc.py` (Tasks 1–3), `mtf.py` (Task 4), `confidence.py` (Task 5).
- Produces: `Method714Strategy` gains `min_confidence`, `filter_mode`, `use_mtf_filter`, `mtf_interval`, `mtf_fast`, `mtf_slow`, `smc_pivot_len`, `smc_sweep_lookback`, `fvg_min_atr_mult`, `extension_min_atr_mult`, `extension_max_atr_mult`, `pa_body_min`, `pa_wick_min`, `clv_min_pct` params, and `symbol`/`asset_class`/`start_date`/`end_date` (needed for the MTF fetch). Each trade record in `trade_log` gains `confidence_score`/`confidence_breakdown`. `run_backtrader` and `main.py`'s endpoint pass the four new required params through. No other task depends on this one.

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_method_714_engine.py — add these, alongside the existing test.
# The existing _synthetic_session_df() fixture and test stay as-is; add:

def test_run_backtrader_method_714_confidence_only_mode_trades_with_low_confidence_ok(mocker):
    # confidence_only mode (the default): a signal with weak individual
    # filters (trend/volume disabled -> those components read as "ok" by
    # construction, matching how _trend_ok()/_volume_ok() already behave
    # when their filter is off) should still trade as long as the score
    # clears min_confidence, even without hard-gating on every filter.
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_volume_filter": False,
        "use_mtf_filter": False,
        "min_confidence": 30,  # session base alone (30) is enough
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] > 0
    first_trade = result["trades"][0]
    assert "confidence_score" in first_trade
    assert "confidence_breakdown" in first_trade
    assert first_trade["confidence_score"] >= 30


def test_run_backtrader_method_714_min_confidence_blocks_low_confidence_entries(mocker):
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_volume_filter": False,
        "use_mtf_filter": False,
        "min_confidence": 95,  # unreachable given every other filter is disabled
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] == 0


def test_run_backtrader_method_714_hard_filters_mode_vetoes_regardless_of_score(mocker):
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": True,  # will fail on this short/flat fixture -> hard veto
        "use_volume_filter": False,
        "use_mtf_filter": False,
        "filter_mode": "hard_filters",
        "min_confidence": 0,
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] == 0
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd analytics && .venv/bin/pytest tests/test_method_714_engine.py -v -k "confidence"`
Expected: FAIL — `TypeError` (unexpected keyword arguments `min_confidence`, `symbol`, etc. — `Method714Strategy`'s params dict doesn't declare them yet)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/strategies/method_714/strategy.py — full replacement

import pandas as pd
import pandas_ta as ta
import backtrader as bt

from .sessions import compute_sessions, DEFAULT_SESSIONS, DEFAULT_TZ
from .retest import generate_signals
from .smc import compute_swing_pivots, compute_structure, compute_liquidity_sweeps, compute_prev_day_sweeps
from .mtf import compute_htf_trend
from .confidence import compute_confidence, extension_ok, pa_quality_ok, clv_ok


class Method714Strategy(bt.Strategy):
    params = dict(
        # Required — used for the MTF fetch, since the strategy only ever
        # receives the base-timeframe DataFrame from backtrader, not the
        # request context that produced it.
        symbol=None,
        asset_class=None,
        start_date=None,
        end_date=None,
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
        position_fraction=0.10,
        # SMC / MTF / confidence — new this slice
        smc_pivot_len=5,
        smc_sweep_lookback=10,
        fvg_min_atr_mult=0.25,
        use_mtf_filter=True,
        mtf_interval="4h",
        mtf_fast=50,
        mtf_slow=200,
        extension_min_atr_mult=0.10,
        extension_max_atr_mult=3.00,
        pa_body_min=0.50,
        pa_wick_min=0.33,
        clv_min_pct=25.0,
        min_confidence=45,
        filter_mode="confidence_only",  # "confidence_only" | "hard_filters"
    )

    def __init__(self):
        self.atr = bt.indicators.ATR(period=self.p.atr_length)
        self.ema_fast = bt.indicators.EMA(period=self.p.ema_fast) if self.p.use_ema_filter else None
        self.ema_slow = bt.indicators.EMA(period=self.p.ema_slow) if self.p.use_ema_filter else None
        self.volume_sma = (
            bt.indicators.SMA(self.data.volume, period=self.p.volume_sma_length)
            if self.p.use_volume_filter
            else None
        )

        df = self.data.p.dataname
        sessions_df = compute_sessions(df, self.p.sessions or DEFAULT_SESSIONS, self.p.tz)
        atr_series = ta.atr(df["high"], df["low"], df["close"], length=self.p.atr_length)
        retest_params = {
            "mode": self.p.mode,
            "retest_max_bars": self.p.retest_max_bars,
            "retest_reject_atr": self.p.retest_reject_atr,
            "retest_invalidate_atr": self.p.retest_invalidate_atr,
        }
        self._signals = generate_signals(sessions_df, atr_series, retest_params).tz_localize(None)
        self._session_starts = sessions_df["session_start"].tz_localize(None)

        pivots_df = compute_swing_pivots(df, piv_len=self.p.smc_pivot_len)
        structure_df = compute_structure(pivots_df)
        sweeps_df = compute_liquidity_sweeps(structure_df, lookback_bars=self.p.smc_sweep_lookback)
        pd_sweeps_df = compute_prev_day_sweeps(sweeps_df, tz=self.p.tz, lookback_bars=self.p.smc_sweep_lookback)

        self._structure_dir = structure_df["structure_dir"].tz_localize(None)
        self._recent_bull_sweep = sweeps_df["recent_bull_sweep"].tz_localize(None)
        self._recent_bear_sweep = sweeps_df["recent_bear_sweep"].tz_localize(None)
        self._recent_pd_bull_sweep = pd_sweeps_df["recent_pd_bull_sweep"].tz_localize(None)
        self._recent_pd_bear_sweep = pd_sweeps_df["recent_pd_bear_sweep"].tz_localize(None)

        if self.p.use_mtf_filter:
            self._htf_trend = compute_htf_trend(
                self.p.symbol,
                self.p.asset_class,
                self.p.start_date,
                self.p.end_date,
                df.index,
                htf_interval=self.p.mtf_interval,
                fast=self.p.mtf_fast,
                slow=self.p.mtf_slow,
            ).tz_localize(None)
        else:
            self._htf_trend = pd.Series(0, index=df.index).tz_localize(None)

        self.entry_price = None
        self.entry_atr = None
        self.stop_price = None
        self.take_profit_price = None
        self._last_exit_price = None
        self._pending_confidence = None
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

    def _mtf_ok(self, direction: int, current_time) -> bool:
        if not self.p.use_mtf_filter:
            return True
        htf_trend = int(self._htf_trend.get(current_time, 0))
        return (direction == 1 and htf_trend == 1) or (direction == -1 and htf_trend == -1)

    def _position_size(self, price: float) -> float:
        notional = self.broker.getvalue() * self.p.position_fraction
        return notional / price if price > 0 else 0

    def _confidence_for_signal(self, signal: int, current_time) -> dict:
        structure_dir = int(self._structure_dir.get(current_time, 0))
        recent_bull_sweep = bool(self._recent_bull_sweep.get(current_time, False))
        recent_bear_sweep = bool(self._recent_bear_sweep.get(current_time, False))
        recent_pd_bull = bool(self._recent_pd_bull_sweep.get(current_time, False))
        recent_pd_bear = bool(self._recent_pd_bear_sweep.get(current_time, False))

        structure_aligned = (signal == 1 and structure_dir == 1) or (signal == -1 and structure_dir == -1)
        sweep_aligned = (signal == 1 and recent_bull_sweep) or (signal == -1 and recent_bear_sweep)
        prev_day_sweep_aligned = (signal == 1 and recent_pd_bull) or (signal == -1 and recent_pd_bear)

        o, h, l, c = self.data.open[0], self.data.high[0], self.data.low[0], self.data.close[0]
        pa_mode = "momentum" if self.p.mode == "momentum" else "contrarian"

        return compute_confidence(
            direction=signal,
            trend_ok=self._trend_ok(signal),
            mtf_ok=self._mtf_ok(signal, current_time),
            atr_ok=self._atr_ok(),
            volume_ok=self._volume_ok(),
            structure_aligned=structure_aligned,
            sweep_aligned=sweep_aligned,
            pa_quality_ok=pa_quality_ok(signal, o, h, l, c, mode=pa_mode, body_min=self.p.pa_body_min, wick_min=self.p.pa_wick_min),
            clv_ok=clv_ok(signal, h, l, c, min_pct=self.p.clv_min_pct),
            prev_day_sweep_aligned=prev_day_sweep_aligned,
        )

    def next(self):
        current_time = self.data.num2date(self.data.datetime[0])
        self.equity_curve.append({"time": current_time.isoformat(), "equity": self.broker.getvalue()})

        if self.p.flatten_at_session_start and self.position:
            is_session_start = bool(self._session_starts.get(current_time, False))
            if is_session_start:
                self._last_exit_price = self.data.close[0]
                self.close()
                return

        if self.position:
            self._manage_open_position()
            return

        signal = int(self._signals.get(current_time, 0))
        if signal == 0:
            return

        atr_value = self.atr[0]

        # Extension band is a hard gate in both filter modes, matching the
        # Pine source's own "(hard gate)" labeling — it is never part of
        # the confidence score.
        if not extension_ok(
            self.data.open[0], self.data.close[0], atr_value,
            min_mult=self.p.extension_min_atr_mult, max_mult=self.p.extension_max_atr_mult,
        ):
            return

        confidence = self._confidence_for_signal(signal, current_time)
        if confidence["score"] < self.p.min_confidence:
            return

        # In "confidence_only" mode (the default), trend/ATR/volume/MTF
        # already shaped the score above and never independently veto —
        # this is a deliberate change from the strategy's earlier
        # reduced-core behavior (which hard-gated on these unconditionally)
        # to reach parity with the Pine source's own two-mode design.
        if self.p.filter_mode == "hard_filters":
            if not (
                self._trend_ok(signal)
                and self._atr_ok()
                and self._mtf_ok(signal, current_time)
                and self._volume_ok()
            ):
                return

        price = self.data.close[0]
        self.entry_price = price
        self.entry_atr = atr_value
        self._pending_confidence = confidence
        size = self._position_size(price)
        if signal == 1:
            self.stop_price = price - atr_value * self.p.sl_atr_mult
            self.take_profit_price = price + atr_value * self.p.tp_atr_mult
            self.buy(size=size)
        else:
            self.stop_price = price + atr_value * self.p.sl_atr_mult
            self.take_profit_price = price - atr_value * self.p.tp_atr_mult
            self.sell(size=size)

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
            self._last_exit_price = price
            self.close()

    def notify_trade(self, trade):
        if trade.isclosed:
            confidence = self._pending_confidence or {"score": None, "breakdown": None}
            self.trade_log.append(
                {
                    "entry_time": bt.num2date(trade.dtopen).isoformat(),
                    "exit_time": bt.num2date(trade.dtclose).isoformat(),
                    "direction": "long" if trade.long else "short",
                    "entry_price": self.entry_price,
                    "exit_price": self._last_exit_price,
                    "pnl": trade.pnl,
                    "confidence_score": confidence["score"],
                    "confidence_breakdown": confidence["breakdown"],
                }
            )
            self._pending_confidence = None
```

```python
# analytics/engines/backtrader_engine.py — full replacement

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

(`backtrader_engine.py` itself needs no change — `params` already flows through unchanged via `**params`. What changes is *what* `main.py` puts into `params` for the `method_714` case, since `Method714Strategy` now requires `symbol`/`asset_class`/`start_date`/`end_date`.)

```python
# analytics/main.py — change the backtest() handler

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
```

```python
# analytics/schemas.py — extend TradeRecord (additive, both new fields Optional)

class TradeRecord(BaseModel):
    entry_time: str
    exit_time: Optional[str] = None
    direction: Literal["long", "short"]
    entry_price: float
    exit_price: Optional[float] = None
    pnl: Optional[float] = None
    confidence_score: Optional[int] = None
    confidence_breakdown: Optional[dict] = None
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_method_714_engine.py -v`
Expected: PASS (all tests, including the pre-existing one and the three new confidence-gating tests)

- [ ] **Step 5: Run the full Python test suite and fix any regression**

Run: `.venv/bin/pytest -v`
Expected: all tests pass. If `test_backtest_endpoint.py`'s `method_714` tests or any other pre-existing test fails because it relied on the old unconditional trend/ATR/volume hard-gate, update that test's params to explicitly set `filter_mode: "hard_filters"` (to keep its original hard-gating behavior) or adjust its assertions to match the new confidence-gated default — whichever matches what the test was actually trying to verify. Do not weaken a test's intent to make it pass; fix the mismatch honestly.

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/strategies/method_714/strategy.py analytics/engines/backtrader_engine.py \
        analytics/main.py analytics/schemas.py analytics/tests/test_method_714_engine.py \
        analytics/tests/test_backtest_endpoint.py
git commit -m "feat(analytics): integrate SMC engine, MTF confirmation, and confidence scoring into Method714Strategy"
```

---

### Task 7: Manual end-to-end verification against real data

**Files:** none (verification only)

**Interfaces:** none — this task consumes the full pipeline from Task 6.

- [ ] **Step 1: Start the three dev servers**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense/analytics && .venv/bin/uvicorn main:app --port 8001 &
cd /Users/sakhilebhayi/Dot/ChartSense/backend && php artisan serve --port 8000 &
cd /Users/sakhilebhayi/Dot/ChartSense/frontend && npm run dev &
```

- [ ] **Step 2: Run a real 714 Method backtest and inspect the confidence breakdown**

```bash
curl -s -X POST http://localhost:8001/backtest -H "Content-Type: application/json" -d '{
  "symbol": "BTC/USDT", "asset_class": "crypto", "strategy": "method_714",
  "params": {"mode": "momentum"}, "start_date": "2025-06-01", "end_date": "2025-08-01"
}' | python3 -m json.tool | head -60
```

Confirm: the response includes `trades` with populated `confidence_score` and
`confidence_breakdown` fields (a dict with all ten component keys), metrics are
realistic (not triple-digit — the position-sizing fix from the original 714
Method slice still applies unchanged), and the request completes without error
despite the extra MTF fetch happening internally.

- [ ] **Step 3: Confirm the MTF fetch is real, not silently skipped**

```bash
curl -s -i -X POST http://localhost:8001/backtest -H "Content-Type: application/json" -d '{
  "symbol": "BTC/USDT", "asset_class": "crypto", "strategy": "method_714",
  "params": {"mode": "momentum", "use_mtf_filter": true}, "start_date": "2025-06-01", "end_date": "2025-08-01"
}' -o /tmp/mtf-on.json -w "%{http_code}\n"

curl -s -i -X POST http://localhost:8001/backtest -H "Content-Type: application/json" -d '{
  "symbol": "BTC/USDT", "asset_class": "crypto", "strategy": "method_714",
  "params": {"mode": "momentum", "use_mtf_filter": false}, "start_date": "2025-06-01", "end_date": "2025-08-01"
}' -o /tmp/mtf-off.json -w "%{http_code}\n"
```

Both must return `200`. Diff the two results' `metrics.trade_count` — they are
expected to differ (MTF changes which signals clear `min_confidence`), which
confirms the MTF filter is actually influencing the outcome rather than being a
no-op.

- [ ] **Step 4: Run the full backend and frontend regression check**

Run `cd backend && php artisan test` — confirm all Laravel tests still pass
unaffected (this slice only touches the Python analytics service). Open
`http://localhost:3000/backtest.html`, run a 714 Method backtest through the
UI end to end, and confirm it still renders results without error (the
frontend doesn't need changes to display the new trade fields since it only
reads `metrics`/`equity_curve`/`disclosure`, not individual trade records —
this is expected and not a gap to fix in this slice).

- [ ] **Step 5: Stop the dev servers**

```bash
pkill -f "uvicorn main:app"
pkill -f "php artisan serve"
```

---

## Plan Self-Review Notes

- **Spec coverage:** swing pivots/BOS/CHoCH (Task 1), order blocks/FVGs (Task 2), liquidity sweeps incl. previous-day (Task 3), MTF confirmation (Task 4), the full 10-component confidence score with extension/PA/CLV gates (Task 5), and integration into the strategy with the two filter modes (Task 6) all map directly to the design doc's in-scope items. The explicit out-of-scope items (visualizing OB/FVG on a chart, live/streaming detection, applying this to MA Crossover/RSI) are untouched by any task.
- **Consistency check:** every new module follows the existing `sessions.py`/`retest.py` pattern exactly — pure functions over a DataFrame, precomputed once in `__init__`, `.tz_localize(None)` applied before storage to match backtrader's naive clock (the exact bug class caught in the original 714 Method slice). `confidence.py`'s weights (session 30, trend 15, MTF 15, ATR 15, volume 10, structure 10, sweep 5, PA quality 10, CLV 5, prev-day sweep 5 = raw 120, capped 100) match the design doc exactly, including the corrected figure from the design's own self-review. Extension band is implemented as a hard gate (`extension_ok`), never a scored component, matching the design's explicit correction.
- **Deliberate behavior change flagged, not hidden:** Task 6's plan text and code comments both call out that the old unconditional trend/ATR/volume hard-gates are removed by default (only enforced in `filter_mode="hard_filters"`) — this is required for parity with the source, and Task 6 Step 5 requires fixing (not weakening) any pre-existing test that assumed the old behavior.
- **No placeholders:** every step has complete, real code.
