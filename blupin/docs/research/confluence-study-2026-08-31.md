# Confluence Study (FVG / OB / Retest / MSS) — 2026-08-31

Four ICT-style confluences tested on the 2y cache with pre-declared definitions, judged on
INCREMENTAL value on top of the live four-filter stack (279 trades / 36.20% / +114.3 ATR).
Baselines reproduced trade-for-trade by every agent.

## The one keeper — RECLAIM tier (wired)

A bar CLOSING back inside the range before 03:00 (usually the sweep bar itself) marks the
stack's premium cohort: **+0.82 ATR/trade vs +0.21 without**, robust in both halves AND the
2026 window. Its complement is still profitable, so it earns a TIER, not a veto: wired as
★ on labels/alerts + optional "Reclaimed-Sweep Size Multiplier" input (default 1.0 = display
only). 2026 reclaim bucket n=12 — indicative there.

## Rejected — and why that's informative

- **FVG magnet** (unfilled overnight gap in the fade's path, ≥0.15 ATR): robust on the RAW
  baseline (35.5% vs 24.7%) but fails on the stack — pnl/trade drops, 2026 worsens, and it
  would skip 155 stack trades worth +63.9 ATR. The live filters already capture it.
- **Displacement FVGs / sweep-into-gap**: structurally untestable at 1h (prevalence 1/566);
  what little exists leans AGAINST fading gap-terminated sweeps. Needs sub-hourly data.
- **Order-block tag** (sweep tested the OB behind the level): real on the raw baseline
  (36.2% vs 26.3%) but inverts inside the stack; skips profitable trades. The tell: has-OB
  vs no-OB flips sign once the noise-floor/isolated filters select clean sweeps.
- **Untested opposite OB**: structural null — on continuous data the next candle always
  opens inside a body-defined OB; the cohort is empty (0/95).
- **MSS/BOS confirmation**: structurally inapplicable — 94% of latest sweeps land on the
  FINAL obs bar (no time to print a shift), and the 6-bar overnight span can't hold a
  two-leg structure sequence (5/594 days qualify).
- **Limit-at-level entry**: beats market on the raw baseline (+100.3 vs +76.4) but is a wash
  on the stack and worse in 2026; forfeits the best runner days (unfilled = 60-70% winners).
  Better-price buffer variant robustly destructive. Entry model unchanged.

## Meta-conclusion

Every "confluence concept" that was real at the baseline level was ALREADY captured by the
four wired filters — the stack IS the confluence engine. The 94%-of-sweeps-on-the-final-bar
fact also explains why pre-decision retest/MSS confirmation can't exist in this engine.
