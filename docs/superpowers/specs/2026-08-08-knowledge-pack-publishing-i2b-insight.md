# Subsystem I2b: `insight` Payload — Design

## Context

I2a built the real Knowledge Pack foundation (Ed25519 signing, full envelope,
real manifest, operator-gated API), scoped to the `metric` payload type only.
This sub-project (I2b) adds the `insight` payload type
(`schemas/insight.schema.json`), reusing that foundation rather than
duplicating it.

Dot.Brain's own reference documentation (`os/19-Knowledge-Packs.md`) names
ChartSense explicitly, suggesting "an easy trust win" for its first insight:
reporting the real AI-analysis honesty fix from Subsystem G — that
`ChartAnalysisController::analyzeChart()` always discloses whether a chart
result is real or placeholder/demo data, rather than presenting demo output
as if it were real. This is grounded directly in shipped code, not a
hypothetical.

## Goal

Publish one real, schema-valid, signed `insight` pack reporting the
AI-analysis honesty disclosure as a falsifiable, evidenced finding.

## Content

```json
{
  "payload_type": "insight",
  "body": {
    "statement": "ChartSense's chart-analysis endpoint (POST /api/chart/analyze) always discloses whether returned pattern-recognition results are placeholder/demo data or computed from real market data, via an explicit is_demo boolean and disclaimer field on every response -- it never presents unlabeled placeholder output as if it were a real analysis.",
    "domain": "trading-analysis-integrity",
    "method": "Manual code audit of ChartAnalysisController::analyzeChart() and its placeholder fallback path, verified against the live API response schema.",
    "evidence": [
      {
        "kind": "external",
        "reference": "chartsense://backend/app/Http/Controllers/ChartAnalysisController.php",
        "note": "is_demo/disclaimer fields present on both the real-analysis (line ~59) and placeholder (line ~95) response branches"
      }
    ],
    "scope": "Site-wide -- applies to every chart-analysis response, not a sampled subset."
  }
}
```

`evidence[].kind` uses `"external"` (the schema's enum:
`metric|dataset|pack|incident|experiment|external`) since source code isn't
one of the other DKP-artifact kinds. `reference` follows the same
`chartsense://` custom-URI convention already used in I2a's `provenance`
block.

No `valid_until` or `counter_evidence` — this isn't a time-bound claim and
there's no known contradicting evidence.

## Architecture (reuses I2a, minimal new surface)

- **No new table.** `knowledge_packs.envelope`/`payload_type` already store
  arbitrary envelope content — `payload_type: "insight"` is just a new
  value.
- **Migration:** `strategy_class` and `account_count` become nullable on
  `knowledge_packs` — both are genuinely inapplicable to a code-audit
  finding (there's no strategy class or account count behind this insight).
  `period` is reused as-is: for `metric` packs it holds `"YYYY-MM"`; for
  `insight` packs it holds a stable slug
  (`"chart-analysis-demo-disclosure-v1"`) serving the same "internal
  idempotency key" role the column's existing comment already describes.
- **`InsightPackGenerator`** (new service): constructor/method takes
  `(slug, statement, domain, method, evidence, scope)` as parameters, not
  hardcoded — a future insight is a new call with new parameters, not a
  rewrite of this class. No aggregation floor (insights aren't
  threshold-gated per the schema or the ecosystem spec — only
  `observation`/`metric` packs carry that requirement). Builds the same
  envelope shape as `ObservationPackGenerator` (contributors, provenance,
  confidence, signatures via `DkpSigner`), with `payloads` containing this
  one `insight` payload.
- **Confidence:** hard-coded `0.85` for this specific insight — the
  evidence is a direct code inspection with no sampling uncertainty (unlike
  `metric` packs' data-driven, sample-size-dependent confidence formula).
  This is a property of *this* insight's evidence quality, not a general
  formula — a future data-driven insight would compute its own confidence
  differently, which is exactly why this is a generator parameter, not
  logic baked into `InsightPackGenerator` itself. *(Design refinement:
  confidence is passed as a parameter to the generator, defaulting to
  nothing — callers must state it deliberately.)*
- **Command:** `php artisan dkp:generate-insight` — a one-shot, no-argument
  Artisan command that calls `InsightPackGenerator` with this specific
  insight's hardcoded content (the statement/domain/method/evidence/scope
  above). No scheduler entry — insights aren't periodic like the monthly
  `metric` cycle; this one is a single historical event, not something to
  regenerate.
- **API:** no controller changes — `GET /api/knowledge-packs` and
  `GET /api/knowledge-packs/{id}` already serve any `envelope` regardless of
  `payload_type`. The existing operator gating applies unchanged.

## Idempotency

Same pattern as `metric` packs: `InsightPackGenerator` checks for an
existing `KnowledgePack` with `payload_type = 'insight'` and
`period = <slug>` before generating — re-running the command after the
first successful run reports "already generated," not a duplicate.

## Testing Plan

- `InsightPackGeneratorTest`: envelope has every required field, the single
  `insight` payload matches `insight.schema.json`'s required fields
  (`statement`, `domain`, `method`, `evidence`), idempotency (second call
  doesn't duplicate), self-verification passes.
- `GenerateInsightCommandTest`: command generates on first run, reports
  already-generated on second run, uses the real test-key trait
  (`UsesDkpTestKey`) from I2a.
- No controller test changes needed — I2a's `KnowledgePackControllerTest`
  already tests the generic list/show behavior against a `metric` pack;
  this slice doesn't need to duplicate that against an `insight` pack to
  prove the same generic code path works (the controller has no
  payload-type-specific branching to test).

## Explicitly Out of Scope for I2b

- A general-purpose "author any insight" admin UI or endpoint — this slice
  ships exactly one real, hardcoded insight, not an authoring system.
- Future data-driven insights (e.g. a confidence-band-vs-win-rate
  correlation finding) — the generator's parameterized design leaves room
  for one later, but computing it is separate work, not part of this slice.
- `incident_report` and `recommendation`/outcome payload types (I2c, I2d).
