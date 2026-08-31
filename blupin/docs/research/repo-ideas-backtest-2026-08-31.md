# Repo-Sourced Hypotheses Backtest — 2026-08-31

Six ideas from the GitHub discovery sweep, tested on the 2y hourly cache with pre-declared
parameters, full grids, halves robustness, and the earn-your-skips rule. Baseline
run(slAtr=1.1): 2y 594 trades 28.79% +89.9 ATR; 2026 window 163 @ 38.04% +79.2.

## DEPLOYABLE (robust, skips earn their keep)

1. **INVERSE prior-day bias filter** (from ikeawesom/xauusd-backtest — whose stated rule
   tested BACKWARDS on our engine): only fade sweeps that CONTINUE the prior day —
   BUY low-sweeps after a DOWN day, SELL high-sweeps after an UP day; fallback days untouched.
   2y: 325 trades, 33.54% (+4.75pp), +98.7 ATR = 110% of baseline pnl on 55% of trades.
   2026 window: 89 trades, **46.07% vs 38.04% (+8.0pp — implies ~54% on the real 30m tester)**,
   +68.7 ATR (87% of window pnl). Positive both halves; skipped sweeps lost −8.8 ATR.
   Strongest cell: BUY after a down day (+0.51 ATR/trade). BUY-after-up-day is robustly
   NEGATIVE (−6.1 ATR, both halves) — the minimal version cuts just that cohort.
2. **NFP-day veto replaces the blanket Friday skip**: Friday damage is almost entirely NFP —
   first-Friday trades went 1-for-24 (−14.0 ATR, losing in both halves) while the other 93
   Fridays were net positive. NFP-only veto: 2y +103.8 ATR vs blanket-Friday's +101.6, better
   in every period, keeps 93 Friday trades. (n=24 skip cell — indicative but 23/24 consistent.
   Live use should use the real BLS calendar, not first-Friday.)
3. **Noise floor k=0.25** (Zarattini methodology, edge-anchored): drop sweeps whose penetration
   is under 0.25× that hour's trailing-14-day mean penetration. Removes 51 micro-poke trades
   (15.7% win, −21.5 ATR). 2y: 30.02% +111.3. Robust; roughly neutral in the 2026 window.
   Shape note: excess is inverted-U — moderate excess (0.5–1.1× noise) is the best cohort and
   extreme excursions (>3.7× noise) are robustly toxic (genuine breakouts; don't fade).
4. **Isolated-extreme exclusion** (the SMC pools test inverted): sweeps of levels with NO
   second touch within 0.5 ATR overnight are toxic fades (14–19% win, −8 to −12 ATR, both
   halves). Excluding them: 2y 29.70% +101.6. Small skip-n (27–35) — indicative-but-consistent.
5. **Non-02:00 sweep trash filter**: h00/h01 latest-sweeps are net losers (34 trades, PF 0.58,
   both halves). Tiny but real.

## REJECTED (tested, failed)

- Stated prior-day bias rule (keeps losers, skips winners — robustly harmful).
- PDH/PDL confluence AND PDH/PDL as replacement levels (both flip between halves; the level
  swap silently converts 69% of days to fallback).
- Multi-touch pool REQUIREMENT at tight tolerances (throws away profitable trades).
- Noise floor k≥0.5 (skipped trades were net profitable — win% cosmetics, pnl destruction).
- HAR-RV gate (forecast skill real, ρ=0.67, but the gate is degenerate in a trending-vol
  regime; naive skip-yesterday's-RV-Q1 is robust but inert in 2026 — keep as insurance only).
- Composite quality score (win% up, expectancy down; fails earn-your-skips).
- Time-boxed Fri/Mon 14:00 exit — REFUTED mechanism: Fri/Mon losses hit in hours 03–09 SAST
  right after entry, NOT in the US-data window (only 7.9% of stop-outs in 14–16h).
- Monday veto: Monday's drag is NOT robust by halves (h1 flat, h2 negative) — no action.

## Verdict

The inverse bias filter is the single biggest robust win-rate lever found in this entire
research campaign (+8pp window, more total profit, both halves agree). The stack worth wiring
(as trade/no-trade tags, signals still shown daily): inverse-bias + NFP veto + noise floor
0.25 + isolated-extreme exclusion. Overlaps between filters mean the combined effect MUST get
one joint backtest before shipping — do not add the individual deltas.

## Joint test + wiring (same day)

Combined-filter backtest (baseline reproduced exactly; full grid in scratch joint.py run):

| Stack | 2y | h1 | h2 | 2026 window |
|---|---|---|---|---|
| Baseline | 28.8% / +89.9 | 25.5 / +43.0 | 32.1 / +46.8 | 38.0% / +79.3 |
| Bias+NFP | 34.3% / +100.5 | 31.1 / +41.8 | 37.2 / +58.6 | 47.1% / +66.4 |
| **Bias+NFP+Noise+Iso (wired)** | **36.2% / +114.3** | 34.1 / +54.2 | 38.2 / +60.1 | **50.0% / +65.4** |

Skipped set (315 trades): 22.2% win, −24.4 ATR over 2y. Window skips were net positive
(+13.9 over 91) — the stack trades window pnl (−14 ATR) for +12pp window win rate; on the
2y whole it adds both. Wired into pine/BluPin_ORD_Ultimate_Combined.pine as **NO TRADE
tags** — signal still displays every day (dimmed arrow + reason on the label + alert tag),
only the order is skipped. Four toggles in "④c Ultimate Trade Filters", defaults on.
Note: the isolated-extreme 0.5-ATR touch tolerance uses chart-TF ATR (tested at 1h ATR),
so on 30m it runs slightly stricter than tested.
