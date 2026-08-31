# Continuation-Detection Study — 2026-08-31

Question: can the 20:00-03:00 window detect that today CONTINUES yesterday, and can the
script monetize it? Four analysts, pre-declared rules, halves + 2026 robustness. Baselines
reproduced exactly by all four.

## The map

- **Base rate: continuation is the MINORITY outcome.** From the 03:00 decision, only 46.0%
  of days continue yesterday (identical in both halves), and just 42.3% in 2026. Blindly
  betting continuation loses −61.8 ATR/2y. Reversal-of-yesterday is the structural default —
  the ground the fade engine stands on — and it STRENGTHENED in 2026.
- **Robust detectors (direction of odds, both halves + 2026):**
  - *Acceptance-ext* (03:00 close beyond the range edge in yesterday's direction): 50.5%.
  - *High conviction* (|overnight drift| > 50% of overnight range): 50.5%, strongest 2026
    confirmation (+6.2pp).
  - *Acceptance × conviction* (exploratory): **56.6%** — the only majority-continuation
    cohort; holds in 2026 (52.5%). → wired as the ⚠ CONT RISK display tag.
  - *Reclaim-counter* (counter edge swept then reclaimed): continuation crushed to 36.4%
    (identical both halves) — and the live bias filter ALREADY excludes all 110 of those
    days. The classifier re-derives the wired filters' wisdom.

## Why no continuation TRADE was wired

- With-yesterday trade on CONT days: +82.6 ATR/2y but front-loaded — h1 +62.2, 2026 **−0.9**
  while the stack's fades on the same days made +31.8. The 2026 regime killed it (same
  pattern as the overnight-trend collapse).
- Composite classifier system (fade REV days, trade CONT days): beats STACK in h1 only;
  loses h2 (+43 vs +60) and 2026 (+28 vs +65). Its dropped STACK trades were positive in
  every window. REJECTED.
- Skip-fades-on-CONT-days: gives up +34.5 ATR of fades positive in every window. REJECTED.
- **Left on the table (not wired): breakout recovery on bias-skipped days** — trading the
  overnight breakout (anti-yesterday) on the 268 bias-filter skip days: +42.2 ATR at 33.6%,
  robust by halves, 2026 +20.8. Portfolio 547 trades / 34.9% / +156.5 vs stack 279 / 36.2%
  / +114.3. Caveats: h2 expectancy only ~+0.08 ATR/trade pre-cost, h2 profit entirely
  2026-driven (2025-H2 bled 6 straight months), dilutes headline win rate, doubles trade
  count. Available on request at reduced size; not a default.

## Meta-conclusion

The engine already embodies the continuation detector: the bias filter IS the
anti-continuation veto (its skips are exactly the 36%-continuation... i.e. the days where
fading would bet WITH yesterday), and the cancel rule is the escape hatch that truncates
losses when continuation wins anyway. What was missing was the WARNING — now on the label.
