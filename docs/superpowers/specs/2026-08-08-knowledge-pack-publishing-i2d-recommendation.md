# Subsystem I2d: `recommendation`/Outcome Payload — Design

## Context

I2a/I2b/I2c completed `metric`, `insight`, and `incident_report`.
`recommendation` is the fourth and final payload type in
`knowledge-pack.schema.json`'s enum. Per Dot.Brain's own worked-examples
doc, there is no distinct `outcome` payload type — "outcome" is described
as a later, follow-up `recommendation`-shaped pack, and no real platform in
the ecosystem has published one yet (`os/19-Knowledge-Packs.md` §5 names
this as the next honest milestone after `metric`/`incident_report`).

The user explicitly chose (during I1's earlier scoping) to include outcome
now, published as a `recommendation`-type pack, self-referential
(`target_platform: "dot-charts"`), with the `impact` block reporting real
data rather than a future prediction. `recommendation.schema.json`'s
`impact` fields are structurally forward-looking (`baseline`/`target`/
`measurement_window`), so this slice reports on a recommendation that is
**already implemented and immediately, structurally verifiable** — not a
projection requiring elapsed time that hasn't happened.

## Content

The recommendation: treat loss-honesty fields (drawdown, losing-period
rate) as structural, non-omittable parts of every generated Knowledge
Pack — already true as of I1/I2a's `ObservationPackGenerator` design (no
code path can omit them). The "outcome" is measured **right now** by
querying the actual `KnowledgePack` rows already published (I2a's `metric`
pack, I2c's `incident_report` pack) for structural compliance — a real,
dynamically-computed percentage, not a hardcoded placeholder.

```json
{
  "payload_type": "recommendation",
  "body": {
    "proposal": "Treat loss-honesty fields (drawdown, losing-period-rate) as structural, non-omittable parts of every generated Knowledge Pack -- not optional or summary-only fields -- so survivorship-filtered performance marketing is prevented at the data-model level rather than relying on policy alone.",
    "target_platform": "dot-charts",
    "rationale": "The ecosystem's loss-honesty rule states published strategy performance must always include drawdowns and losing periods -- survivorship-filtered marketing is both success theater and a regulatory violation. This was implemented structurally, not just as policy, starting with I1: ObservationPackGenerator's code path has no parameter or branch capable of omitting the max-drawdown or losing-period metrics.",
    "evidence": ["<real pack_id of the I2a metric pack, filled in at generation time>"],
    "impact": {
      "business": {
        "metric": "trading.loss_honesty_field_coverage_pct",
        "baseline": 0,
        "target": 100,
        "measurement_window": "Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window."
      },
      "user": {
        "metric": "trading.disclosure_transparency_pct",
        "baseline": 0,
        "target": 100,
        "measurement_window": "Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window."
      },
      "dopamine": {
        "metric": "trading.ethical_disclosure_compliance_pct",
        "baseline": 0,
        "target": 100,
        "measurement_window": "Immediate -- structural invariant verified per-pack at generation time, not sampled over a future window."
      }
    },
    "rollback": {
      "procedure": "Revert ObservationPackGenerator (and InsightPackGenerator/IncidentPackGenerator, which follow the same envelope-building pattern) to make loss-honesty fields conditional or omittable.",
      "blast_radius": "All future generated Knowledge Packs would lose the structural loss-honesty guarantee -- a policy-only guarantee, not a code-enforced one.",
      "watch_signals": ["trading.loss_honesty_field_coverage_pct", "trading.gate_rejection_count"]
    },
    "review_window_days": 1
  }
}
```

The `dopamine` axis is a genuine schema requirement (`impact.dopamine` is
required, not optional), not a claim that this recommendation is an
engagement feature. The chosen metric reflects the ecosystem's own named
*safe* pattern from Dot.Charts' platform doc §8: "Shared: strategy-class
performance with full drawdown context ... at strategy level" is explicitly
listed as the sanctioned alternative to prohibited engagement hooks
(win-rate badges, streaks) — full-drawdown disclosure is the ecosystem's
answer to dopamine-loop risk in this domain, not a metric intended to
*increase* engagement.

`evidence` references the real `pack_id` of the already-published `metric`
pack (I2a) — a genuine artifact, not a placeholder UUID.

`review_window_days: 1` (the schema's minimum) — this recommendation's
outcome doesn't require time to elapse; it's a structural fact, verifiable
the moment it's checked.

## Architecture (identical pattern to I2b/I2c)

- **No new table/columns.**
- **`RecommendationPackGenerator`** (new service): unlike
  `InsightPackGenerator`/`IncidentPackGenerator` (fully static hardcoded
  content passed in), this one **computes** the `business`/`user`/
  `dopamine` baseline/target numbers dynamically: it queries all existing
  `KnowledgePack` rows with `payload_type = 'metric'` and checks each
  one's `payloads[]` for the presence of the 2 loss-honesty metric names
  (`trading.strategy_max_drawdown_worst_pct`,
  `trading.strategy_losing_period_pct`). The computed coverage percentage
  becomes the real `target` value reported (and `baseline` is always `0`,
  representing "before this structural guarantee existed"). If zero
  `metric` packs exist yet, the recommendation still generates (the
  guarantee is a code-level fact independent of how many packs have been
  produced), reporting `100` as the target with a note that no packs have
  been produced to sample from yet if the count is 0 — see Testing Plan for
  the exact assertion.
- **Command:** `php artisan dkp:generate-recommendation` — one-shot, no
  arguments, computes the real coverage percentage from the database at
  run time (not hardcoded like I2b/I2c's commands), then calls the
  generator. No scheduler entry.
- **Confidence:** `1.0` — this reports a directly, structurally verified
  code-level fact (not a sampled measurement or a code-audit inference),
  the highest-confidence category among all 4 payload types so far.
- **API:** no changes.

## Idempotency

Same `period`-as-slug pattern:
`"loss-honesty-structural-invariant-recommendation-v1"`.

## Testing Plan

- `RecommendationPackGeneratorTest`: envelope has every required field, the
  `recommendation` body has every field `recommendation.schema.json`
  requires (`proposal`, `target_platform`, `rationale`, `impact` with all 3
  axes, `rollback`, `review_window_days`), the computed coverage percentage
  is correct against a fixture with a known mix of compliant/non-compliant
  `metric` packs (a non-compliant fixture is only reachable in the test —
  real packs are always compliant by I1's own invariant, but the
  computation logic itself must be provably correct against a case that
  could theoretically violate it), zero-existing-packs case reports `100`
  (the guarantee is code-level, not sample-dependent), idempotency,
  self-verification.
- `GenerateRecommendationCommandTest`: generates on first run, reports
  already-generated on second run, uses `UsesDkpTestKey`.

## Explicitly Out of Scope for I2d

- A distinct `outcome` payload type — none exists in the real schema; this
  slice uses `recommendation` per the ecosystem's own worked-examples
  convention, as scoped.
- A general recommendation-authoring endpoint.
- Any recommendation *about* a future prediction requiring elapsed time —
  this slice's content is immediately, structurally verifiable by design.
- This closes out all 4 Knowledge Pack payload types for Subsystem I2 —
  the inbound MNPI gate, dual-control review, and append-only audit log
  remain out of scope for the entire I2 series (deferred, as originally
  scoped in I1).
