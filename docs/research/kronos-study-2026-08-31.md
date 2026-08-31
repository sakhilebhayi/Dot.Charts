# Kronos Integration Study — 2026-08-31

Question: can the Kronos foundation model (github.com/shiyu-coder/Kronos, 24.7M-param
Kronos-small, MIT license) improve the BluPin ORD+ULT edge?

Method: rebuilt local env (torch 2.13/MPS, models from HF cache), day-horizon pilot —
at each day's 03:00 SAST decision bar, feed 400 hourly bars, autoregressively forecast
the rest of the day, join with the BluPin sim. Two years tested (2025 n=245, 2026 n=160),
two configs (stock, and with the repo's known `.eval()` dropout bug fixed + paper settings
T=0.6/top_p=0.9). Repo dossier: full API/finetune/community-evidence review.

## Findings

| Angle | 2026 | 2025 | Verdict |
|---|---|---|---|
| Day-call direction accuracy | 55.6% | 51.0% | NOT TRADABLE — unstable across years, n.s.; matches community (~50–53% everywhere) |
| BluPin win: Kronos agrees vs disagrees | 41.2 vs 36.0% | 25.0 vs 21.3% | WEAK — win-rate gap positive both years (+4–5pp) but pnl inconsistent (2026 disagree days carried MORE pnl). Monitor, don't wire. |
| Range forecast corr w/ actual day range | +0.50 | +0.26 | REAL SKILL — positive in all 4 runs (0.26–0.59); the model's genuine ability |
| BluPin on predicted-WIDE vs NARROW days | 41.2%/+55.7 vs 36.2%/+25.7 ATR | 25.2%/−15.2 vs 22.1%/−25.8 | **THE FINDING** — wide beats narrow on BOTH metrics in BOTH years, in both configs |

- The `.eval()` fix (repo issue #382 — stock inference runs with dropout ON) lifted direction
  accuracy ~3–6pp; still not enough. The range read prefers multi-sample averaging (higher
  corr at T=1.0 × 3 samples than single sharp path).
- `predict(sample_count=N)` averages paths before returning (kronos.py:467) — per-path
  dispersion (community: Spearman +0.375 vs realized vol) needs a one-line patch.

## Fine-tuning: NOT recommended now

Community evidence: "improves validation loss, not trading outcomes" (issue #355); crypto
finetunes showed no effect; the CSV finetune pipeline still contains an UNFIXED lookahead
leak (normalizes over the forecast window, PR #263 unmerged); compute undocumented
(one report: 8×48GB GPUs); zero gold finetuning results exist anywhere.

## Recommended integration: the 03:00 Day-Quality Gate

Kronos can't run in Pine. Use it as a companion script on this Mac (env already built):
at ~03:05 SAST, forecast the rest of the day (N=5 samples, per-path ranges), classify the
day WIDE/NARROW vs median, deliver the tag (notification/file). Trading rule to validate
forward: full size on predicted-WIDE days, reduced/skip on NARROW. Expected from the two-year
record: +3–5pp win rate on traded days with pnl concentrating in the traded set. Fits the
established loss anatomy — ride-to-day-end starves on truncated days (the Monday disease).
Optional untrusted overlay: the agree/disagree tag, tracked forward only.

Footnote: our Pine analog BluPin_KronosLite (56.5% next-bar) remains competitive with the
real model's direction read — the tokenize→context→analog idea already shipped.
