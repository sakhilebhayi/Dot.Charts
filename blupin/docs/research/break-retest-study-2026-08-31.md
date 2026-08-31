# Break-and-Retest Study — 2026-08-31

Question: can a break-and-retest read give correct-direction signals on breakout days?
Three analysts; all baselines reproduced exactly; halves + 2026 robustness.

## The premise INVERTS on breakout (bias-blocked) days — nothing wired there

- Retests are near-universal (88–98%) and near-instant: the 03:00 close already sits inside
  the retest band on 49–77% of days and 85–92% of first retests print ON the 03:00 bar.
  The 03:30 breakout read effectively IS the retest entry; waiting adds nothing.
- Anatomy inversion (robust both halves): RUNAWAY days that never come back are the elite
  continuers (+3.3 ATR avg, ~100% positive); retest+reject days are a coin flip.
- Every retest entry variant (limit at edge / ±0.25 ATR, confirmation close, 12:00 fallback)
  trails the +42.2 ATR market benchmark; the confirmation leg wins only 32.2% — BELOW the
  stack's 36.2% — so it would dilute the win rate (12× worse dilution-per-ATR than the
  plain market breakout). Wire nothing on this universe.

## Where retest logic IS real — ⚠ CONT RISK days (wired)

On acceptance days retests come later (afternoon tail) and the ordering is robust in both
halves: RUNAWAY continues best > RETEST+REJECT continues (+0.69 avg) > RETEST FAILED
collapses (−2.3 ATR from 03:00 — the most toxic state in the study). The retest-reject
continuation entry (tol 0.5 ATR, until day end, dir = yesterday) is the study's only
sign-robust positive retest trade: **+46.7 ATR 2y at 35.5% win, positive both halves,
+7.8 in 2026 while the raw 03:30 continuation entry went −0.9** — the retest gate mainly
dodges the failed-acceptance disasters. Caveats: h2 +10.0 thin; 2026 fills <30 (indicative).

## Wired

On ⚠ CONT RISK no-trade days the script now watches after 03:30: pullback to within
0.5 ATR of the accepted edge → bar closes on the continuation side = **RETEST CONT BUY/SELL**
label + alert (direction = yesterday's); bar closes back through the edge = **RETEST FAILED ⚠**
(reversal-risk warning). Display + alert by default; "Trade the Retest-Continuation Signal"
toggle (default off) enters at the confirmation close, SL from the ATR stop input, ride to
day end. Breakout/bias days deliberately carry no retest signal (tested inverted).
