# Subsystem I3: Inbound MNPI Content-Materiality Gate — Design

## Context

`platforms/dot-charts.md` §7 describes a bidirectional compliance gate —
outbound (built across I1/I2: signal disclosure, loss-honesty) and inbound
(MNPI screening + "classification and floor verification"). This
sub-project builds the inbound half, scoped to what's actually groundable.

**A real gap surfaced during design:** the inbound gate's "classification
and floor verification" check assumes pack envelopes carry a
`classification` field. `brain.security.md` §2 describes a real
classification taxonomy (`public → ecosystem → restricted → sensitive`,
"carried on every pack, node, edge") — but the actual normative schema this
whole series has built against, `schemas/knowledge-pack.schema.json`, has
**no such field**, and neither does `schemas/platform-manifest.schema.json`.
This is an inconsistency in the ecosystem's own documentation (a real
concept described in prose, absent from the schema that would carry it),
not something to paper over. Per the user's explicit choice, this slice
builds only the MNPI content-materiality screen — the half groundable in
real, inspectable pack content — and skips the classification/floor check
entirely, flagged here rather than worked around.

## Goal

An inbound gate that screens incoming pack content against a maintained
instrument map, fail-closed, with every decision (pass or reject) logged
append-only and rejections dispatching the real
`trading.compliance.gate_rejected` event named in `platforms/dot-charts.md`
§3 (previously unimplemented — no code path in this repo has ever emitted
it).

## Instrument Map

`config/dkp_instrument_map.php` — a small, explicitly-labeled seed map
(domain keyword → instrument ticker(s)), not a comprehensive real dataset:

```php
<?php

// Seed instrument map for the inbound MNPI content-materiality screen.
// Deliberately small and illustrative -- NOT a comprehensive or
// professionally-maintained instrument-mapping dataset. Mirrors the
// Kolomela/Kumba Iron Ore example already used in Dot.Brain's own
// brain.security.md §6 worked example (a false-correlation poisoning
// attempt targeting a Dot.Charts recommendation via a commodity/mining
// domain reference), rather than inventing a new fictional mapping.
// Per platforms/dot-charts.md §12's open question, this map's ongoing
// maintenance ownership is unresolved ecosystem-wide -- this seed exists
// so the gate has something real to check against, not as a claim that
// ChartSense unilaterally owns instrument-mapping maintenance.
return [
    'kolomela' => ['KIO.JO'],
    'sishen' => ['KIO.JO'],
    'kumba iron ore' => ['KIO.JO'],
];
```

Keys are lowercase match phrases; values are the instrument ticker(s) a
match would be material to.

## `InboundMnpiGate` Service

- `screen(array $pack): array` — takes a raw pack envelope-shaped array
  (`title`, `summary`, `payloads[]` at minimum; doesn't require a fully
  valid signed DKP envelope, since this simulates content arriving via a
  transport that doesn't exist yet).
- Concatenates `title`, `summary`, and every `payloads[].body` value
  (recursively stringified) into one lowercase haystack.
- Checks the haystack for every instrument-map key as a substring match.
- **Any match → reject** (fail-closed; no attempt to judge "is this
  already public" from free text — an unreliable, unsafe judgment to
  automate, consistent with `brain.architecture.md`'s "fail to propose
  nothing, never propose unvalidated" principle).
- No match → pass.
- Returns `['decision' => 'pass'|'reject', 'reason' => ?string, 'matched_keywords' => array]`.
- This is intentionally coarse (substring matching, not semantic/NLP
  analysis) — an honest limitation stated in code comments, not a claim of
  real materiality-detection sophistication.

**Signature verification of inbound packs is explicitly out of scope** —
ChartSense has no registry of other platforms' public keys to verify
against (no registry infrastructure exists anywhere in the ecosystem, per
I1/I2's established findings); this gate screens content only.

## Audit Log

New table `dkp_gate_decisions` (append-only — no update/delete routes ever
exposed, matching how `knowledge_packs` itself has no update path):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `direction` | string | `"inbound"` for this slice (column included now so a future outbound-gate-decision-logging slice doesn't need a schema change) |
| `decision` | string | `"pass"` or `"reject"` |
| `reason` | string, nullable | e.g. `"MNPI content-materiality match"` |
| `matched_keywords` | json, nullable | the instrument-map keys that matched |
| `pack_title` | string | snapshot — the pack isn't otherwise persisted by this gate |
| `pack_summary` | text | snapshot |
| `decided_at` | timestamp | |

## Event

`App\Events\ComplianceGateRejected` (`packTitle`, `matchedKeywords`),
dispatched only on `reject`, with a logging listener — same pattern as
I1's `StrategyPerformanceCycleCompleted`. Satisfies
`platforms/dot-charts.md` §3's `trading.compliance.gate_rejected` event,
target frequency "low — target 0" (i.e. rejections should be rare; the
event existing and firing correctly matters more than volume).

## API

`POST /api/knowledge-packs/ingest-check` — operator-gated (same
`auth:sanctum` + `operator` middleware as every other Knowledge Pack
endpoint). Body: `{title, summary, payloads}` (the minimum shape the gate
scans). Runs `InboundMnpiGate::screen()`, writes the audit-log row
regardless of outcome, dispatches the event on reject, returns
`{decision, reason, matched_keywords}`.

This is the realistic point a future real transport handler would call
when packs start actually arriving — no such transport exists yet
(confirmed ecosystem-wide by Dot.Brain's own documentation), so this
endpoint is exercised manually/by an operator for now, not by live traffic.

## Testing Plan

- `InboundMnpiGateTest`: a pack referencing a mapped keyword (e.g.
  "Kolomela production forecast") is rejected with the matched keyword
  returned; a pack with no matches passes; matching is case-insensitive;
  a match anywhere in `payloads[].body` (not just `title`/`summary`) is
  caught.
- `KnowledgePackIngestCheckControllerTest`: operator can call the endpoint
  and gets the correct decision for both pass/reject cases; every call
  writes exactly one `dkp_gate_decisions` row (regardless of outcome);
  a reject dispatches `ComplianceGateRejected`; a pass does not;
  non-operator gets 403; unauthenticated gets 401.

## Explicitly Out of Scope for I3

- Classification/floor verification (no real schema field exists for it —
  flagged above, not built around with an invented field).
- What ChartSense does with an *accepted* pack — wiring it into strategy
  generation, model features, or any real downstream use. Undefined,
  substantially larger work, not part of this slice.
- Signature verification of inbound packs (no registry of other platforms'
  keys exists).
- Any real transport/delivery mechanism receiving packs automatically —
  none exists ecosystem-wide.
- Semantic/NLP-based materiality detection — the screen is intentionally a
  coarse, honest substring match, not a claim of real analysis
  sophistication.
- This closes the compliance-gate portion of the original 9-subsystem gap
  audit's Subsystem I scope, to the extent it's groundable in what
  actually exists in this codebase and the real ecosystem schemas today.
