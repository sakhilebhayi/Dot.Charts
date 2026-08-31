# Daily Direction model — analysis record (2026-08-29)

Data: 15 years of daily gold OHLC (GC=F, 2011-08-29 → 2026-08-28, 3,569 usable
bars after warm-up). Chart context confirmed by screenshot: user's open chart is
TVC:GOLD 1D. Caveat: GC=F uses exchange-day boundaries, the indicator uses
Africa/Johannesburg midnight days — feature statistics transfer, exact values
may drift slightly.

Outcome variable: next day's OPEN → CLOSE direction (what a confirmed intraday
signal can actually capture). Baseline P(up) = 50.4%.

## What did NOT survive testing (≈ coin flips or unstable across halves)

- "Bullish day → bullish tomorrow": 49.7%.
- Strong momentum candles (body ≥ 70%): ~49.5% both directions.
- Engulfing patterns: wildly unstable across halves (bull engulf 33.9% → 62.7%).
- Outside bars, gap-up opens, breakout closes above prior high: unstable.
- Consecutive up/down runs: no stable signal.

## What survived (stable in BOTH halves of the sample)

| Feature (yesterday) | n | P(next up) | halves |
|---|---|---|---|
| Weak-close breakdown (down, close < prior low, clv ≤ .3) | 631 | 54.5% | 52.6 / 56.3 |
| …same, inside an uptrend (close > EMA20) | 174 | **59.2%** | 61.8 / 58.0 |
| Big down day (ret < −1.5%) | 240 | 54.2% | 55.3 / 53.3 |
| Up day closing weak (clv ≤ .5) | 260 | 56.2% | 56.6 / 55.7 |
| Long upper wick (≥ 40% of range) | 691 | 53.3% | 52.1 / 54.4 |
| Big up day (ret > +1.5%) → next day DOWN | 255 | 53.3% dn | 53.9 / 52.9 dn |
| Bearish inside day → next day DOWN | 285 | 53.3% dn | 53.8 / 52.6 dn |
| EMA20 rising (trend carry) | 1923 | 51.6% | 49.4 / 53.3 |

Theme: gold's daily rhythm is MEAN REVERSION AFTER EXTREMES plus a small trend
carry. Shakeout/capitulation days get bought; euphoria days get faded; quiet
bearish inside days drift on down.

## Final model (6 transparent terms)

score = (EMA20 rising ? +1 : −1)
      + 2 if weak-close breakdown day
      + 1 if capitulation day (ret < −1.5%)
      + 1 if up day closing weak (clv ≤ .5)
      − 2 if euphoria day (ret > +1.5%)
      − 1 if bearish inside day

| Threshold | Coverage | Accuracy | 1st half | 2nd half |
|---|---|---|---|---|
| \|score\| ≥ 2 | 22.5% of days | 52.9% | 52.9% | 52.9% |
| \|score\| ≥ 3 (default) | 11.1% of days | 54.9% | 55.2% | 54.7% |
| score = +4 bucket | 89 days | 58.4% | 60.9% | 57.6% |

Honest framing: this is a modest, stable edge — NOT a 70–80% oracle. Anyone
getting those numbers from daily candles is overfitting. The intraday
confirmation stage exists precisely to upgrade quality: the signal only prints
when the new day's structure already moves the predicted way before the cutoff.

Confidence display mapping (historical frequencies, embedded in the indicator):
|score| ≥ 4 → 58 · = 3 → 55 · = 2 → 52.

Confirmation design (not backtestable from daily data — measured forward by the
indicator's own Data Window stats): opening-range (first 30 min) breakout in
the bias direction with displacement ≥ 5% of yesterday's range, before 03:00;
close through the opposite extreme first ⇒ INVALIDATED, no signal.

---

## v2.0 revision (2026-08-29) — Opening-Price Rejection model

The user replaced the 6-term statistical model with an opening-price sequence
model: which side of yesterday's open was tested FIRST (meaningful excursion),
and did price return to the open. Backtested on 598 Johannesburg trading days
reconstructed from hourly GC=F bars (2024-04 → 2026-08), sequence-accurate.
Baseline P(day up, open→close) in this window: 55.3% (strong bull regime).

| Wick threshold | Class → prediction | n | correct | vs baseline |
|---|---|---|---|---|
| 25% avg range | Rejection → BUY  | 207 | **62.8%** | +7.5 |
| 25% avg range | Rejection → SELL | 191 | **51.3%** | +6.6 (down base 44.7%) |
| 25% avg range | Continuation → BUY  | 110 | 50.0% | −5.3 |
| 25% avg range | Continuation → SELL |  68 | 41.2% | −3.5 |

15% threshold: rejection edge shrinks (noise qualifies); 35%: rejection class
starves. Default 25% of 5-day average range; return must touch the open
(tolerance configurable); doji 10%.

Verdict: the REJECTION setups carry a real, two-sided edge. CONTINUATION has
no edge (below baseline both ways) — implemented per spec but toggleable
(`useCont`), with its honest historical numbers displayed on the labels.
Confidence figures shown by v2.0: Rejection BUY 63 / SELL 51, Continuation
BUY 50 / SELL 41 — historical frequencies, not invented probabilities.

---

## v2.1 confluence pass (2026-08-29)

Three candidate confluences tested on the 398 rejection setups before coding:

1. **Trend alignment (daily EMA20)** — FAILED. Aligned 58.5% vs counter 56.3%
   with unstable halves (50.5→67.4); SELL-in-downtrend inverted to 46.2%.
   The 15y daily finding did not transfer to sequence-based rejections. Not added.
2. **Rejection depth** — FAILED. Shallow 57.5 / medium 56.0 / deep 58.2,
   no clean separation, unstable halves. Not added.
3. **Rejection completion** — REAL, inverted, SELL-specific. SELL rejection
   with the day already closed down: 38.4% (30.3/45.0) — the move was spent,
   next day mean-reverts. SELL rejection closing up anyway: 59.3% (63.0/56.2).
   BUY rejections unaffected (~63% either way).

Implemented in v2.1: spent-move filter (default on → spent SELLs become
NEUTRAL, marked "BIAS ▼ SPENT"; off → they display 38% honestly). Confidence
figures now: REJ BUY 63 / REJ SELL 59 (38 if unfiltered spent) / CONT 50/41.
Plus forward-only instrumentation: +SWEEP tag (confirmation preceded by a
sweep of yesterday's opposite extreme) and early-confirmation (≤01:30) hit
rates, both in the Data Window.
