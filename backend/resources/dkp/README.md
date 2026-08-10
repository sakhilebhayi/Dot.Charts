# Vendored: Dot.Brain's Knowledge Pack schema

`knowledge-pack.schema.json` is a vendored copy of Dot.Brain's real, authoritative
envelope schema, not a fabricated approximation of one.

- **Source:** `~/Dot/Dot.Brain/schemas/knowledge-pack.schema.json`
- **Vendored at commit:** `6e6df48` (2026-08-01)
- **Why vendored, not referenced live:** Dot.Brain is a sibling repo on this
  machine during development, but ChartSense's test suite must not depend on
  another repository's filesystem path existing at test time (that only works
  here, not in CI or on anyone else's machine). A local copy makes the
  conformance check ([tests/Unit/DkpEnvelopeSchemaConformanceTest.php](../../tests/Unit/DkpEnvelopeSchemaConformanceTest.php))
  portable and deterministic.
- **Staleness risk:** this file will drift from Dot.Brain's real schema if
  Dot.Brain's schema changes and this copy isn't refreshed. There is no
  automated sync between the two repos today (see wiki.md §5 — no transport
  path exists yet either, so there's no natural "sync happens as a side
  effect of publishing" moment). Re-vendor by re-running the `cp` above and
  checking the new commit hash in whenever this file's conformance stops
  being verified against reality.
