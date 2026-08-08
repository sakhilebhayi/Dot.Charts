# Subsystem I2c: `incident_report` Payload — Design

## Context

I2a/I2b established the real Knowledge Pack foundation and proved the
pattern extends cleanly to new payload types without touching the shared
table/API/signing code. I2c adds `incident_report`
(`schemas/incident.schema.json`), reusing that same pattern.

The real content is a genuine, already-fixed infrastructure bug from this
session: commit `73c4f3d`, found during Strategy Builder F2's manual
verification — `storage/framework/{cache,sessions,views}` and
`storage/logs` were entirely missing from the checkout, which turned every
`firstOrFail()` 404 (in `CustomStrategyController` and the pre-existing
`BacktestController`) into a 500 "Please provide a valid cache path" error
under `php artisan serve`. Invisible to `php artisan test` (different
config, doesn't need these paths) — only live manual verification caught
it.

## Content

```json
{
  "payload_type": "incident_report",
  "body": {
    "incident_id": "chartsense-inc-2026-08-08-001",
    "kind": "incident",
    "severity": "sev3",
    "detection": {
      "detected_at": "2026-08-08T17:46:20Z",
      "detected_by": "Manual verification during Strategy Builder F2 implementation pass (live php artisan serve testing)",
      "method": "A request expected to return 404 (nonexistent custom-strategy ID) returned 500 instead, surfaced only under the live dev server, not the test suite"
    },
    "impact": {
      "systems": ["CustomStrategyController", "BacktestController", "Laravel framework cache/session/view compilation"],
      "description": "Every firstOrFail()-based 404 response (in both controllers) was replaced by a 500 'Please provide a valid cache path' error under php artisan serve, because storage/framework/{cache,sessions,views} and storage/logs did not exist in the checkout at all, despite .gitignore expecting .gitkeep placeholders for them."
    },
    "timeline": [
      { "at": "2026-08-08T17:40:00Z", "event": "F2 manual verification step requested a nonexistent custom-strategy ID, expecting a 404 JSON response" },
      { "at": "2026-08-08T17:43:00Z", "event": "Observed a 500 'Please provide a valid cache path' error instead; root-caused to missing storage/framework and storage/logs directories" },
      { "at": "2026-08-08T17:46:20Z", "event": "Fix applied: restored the four missing directories with .gitkeep placeholders, verified the same request now returns 404 as expected" }
    ],
    "root_cause": {
      "statement": "storage/framework/{cache,sessions,views} and storage/logs did not exist in this checkout at all -- Laravel's live dev server needs these directories to write compiled views, cache entries, and sessions, and their absence caused every firstOrFail()-triggered exception handling path to fail with a filesystem error before it could render the intended 404 response.",
      "contributing_factors": [
        ".gitignore expected .gitkeep placeholders in these directories, but the placeholders themselves were never committed",
        "php artisan test uses a different runtime config that doesn't need these paths, so the automated test suite never exercised this failure mode"
      ]
    },
    "corrective_actions": [
      {
        "action": "Restore the missing storage/framework/{cache,sessions,views} and storage/logs directories with .gitkeep placeholders",
        "owner": "ChartSense Platform Lead",
        "due": "2026-08-08",
        "status": "done"
      }
    ],
    "lessons": [
      {
        "lesson": "Directories a framework needs at runtime but that git can't track empty (cache/session/log/view-compilation paths) must have committed .gitkeep placeholders verified present in a fresh checkout -- a passing test suite alone does not prove a live server will boot cleanly, if the test runtime config sidesteps the same paths.",
        "verified": true,
        "verification_evidence": "Fix applied and independently re-verified live (php artisan serve, same request now returns the correct 404) in the same session; the missing-directories failure mode is a standard, well-understood Laravel deployment gotcha, not a novel or unverified claim."
      }
    ]
  }
}
```

`resolved_at` is omitted (optional per the schema) — the fix landed in the
same session it was found, effectively immediately; a separate resolution
timestamp field would just duplicate the last `timeline` entry.

## Architecture (identical pattern to I2b)

- **No new table/columns** — `strategy_class`/`account_count` are already
  nullable (I2b); `payload_type: "incident_report"` is just another value.
- **`IncidentPackGenerator`** (new service): parameterized like
  `InsightPackGenerator` — takes the full incident body as a structured
  parameter set (not hardcoded inside the generator), builds the same
  envelope shape, signs via `DkpSigner`, self-verifies, persists. Idempotent
  via the same `period`-column-as-slug convention
  (`"storage-framework-missing-2026-08-08"`).
- **Command:** `php artisan dkp:generate-incident` — one-shot, no
  arguments, this specific incident's content hardcoded in the command
  (same scope discipline as I2b: not a general incident-authoring tool).
  No scheduler entry.
- **Confidence:** `0.95` — this is a directly-observed, already-verified,
  already-fixed incident with a concrete commit as evidence, warranting
  higher confidence than I2b's code-audit insight (0.85) or a `metric`
  pack's sample-size-dependent formula.
- **API:** no changes — `index`/`show` already serve any `envelope`
  regardless of `payload_type`.

## Testing Plan

- `IncidentPackGeneratorTest`: envelope has every required field, the
  `incident_report` body has every field `incident.schema.json` requires
  (`incident_id`, `kind`, `severity`, `detection`, `impact`, `timeline`,
  `root_cause`, `corrective_actions`, `lessons`), idempotency, self-
  verification.
- `GenerateIncidentCommandTest`: generates on first run, reports already-
  generated on second run, uses `UsesDkpTestKey`.

## Explicitly Out of Scope for I2c

- A general incident-authoring system or endpoint — one hardcoded, real
  incident, matching I2b's precedent.
- Automatic incident detection/reporting from application error logs — this
  is a manually-authored report of a specific, already-fixed, already-
  understood incident, not a monitoring integration.
- `recommendation`/outcome payload type (I2d).
