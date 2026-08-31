# Six-video study → edge upgrades (2026-08-30)

Videos (frames extracted + read; audio not recoverable):
A @charttactix — SMC schematic: liquidity sweep → MSS → retrace entry, 2-3R
B @johnwickwatcher — "forbidden prop strategy": mark the day's first 1h range
  candle on gold, sell the 15m breakdown, SL above, TP nearest level
C @amorecrt10 — CRT (Candle Range Theory): HTF range candle, next candle
  purges a side, LTF zone entry (FVG/BPR), target opposite side; TF table
  Weekly→4H, Daily→1H, 4H→15m
D @opulent_edge — "714 Opening Price explained": short the break back under
  the opening price, SL above open, TP ~1.6R with partial
E @shadow.intel.trades — DXY/gold inverse-correlation confirmation: when DXY
  bounces from its zone, take the opposite gold trade
F @opulent_edge — "3 ways to spot 714 continuation": repeated retests of the
  opening price that hold = continuation

Audit vs our stack: A and C ARE our Ultimate engine's concept (CRT is the
same trade under another name — independent validation of the design);
B is the ORB family (tested weak on gold); D/F are the 714 creator's own
opening-price doctrine (already the session engine's foundation). The only
new independent information source in all six videos: E (intermarket DXY).

## Backtests vs the rank-1 sweep engine (304 sweep days, ~2y hourly)

| Variant | n | win | P/L | halves |
|---|---|---|---|---|
| Baseline (day-end exit) | 304 | 53.9% | +69.7% | +40.7/+29.1 |
| DXY agrees (video E's rule) | 135 | 44.4% | +2.8% | +2.1/+0.6 |
| **DXY disagrees (inverted rule)** | 168 | **61.3%** | **+66.4%** | +37.7/+28.7 |
| MSS-confirmed only (video A) | 47 | 59.6% | +14.1% | starves the edge |
| CRT opposite-extreme target (video C) | 304 | 62.2% | +15.1% | caps the trend days |

## The finding

Video E's correlation rule is EXACTLY BACKWARDS for sweep-fade signals — and
inverting it is the one real upgrade in the set. When DXY has already moved
in the "supporting" direction over the prior 24h, gold's correlated move has
already happened: the sweep is late, the edge is spent (44.4% win, ~zero
P/L). When DXY has NOT yet confirmed, the sweep is early information: 61.3%
win, +66.4% — 95% of the baseline P/L on 55% of the trades, stable halves.
Same spent-move physics as the Daily Direction SELL filter.

MSS gating and opposite-extreme targets are win-rate cosmetics that forfeit
the trend days paying for the system. Not adopted as defaults.

Recommended implementation: DXY spent-move filter on the Ultimate engine —
at signal time require the 24h DXY change to NOT already support the gold
direction (BUY needs DXY flat-or-up over 24h; SELL needs DXY flat-or-down).
Pine: request.security("TVC:DXY", ...) confirmed closes, non-repainting.
