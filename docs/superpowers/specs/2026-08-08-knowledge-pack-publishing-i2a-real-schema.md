# Subsystem I2a: Real Envelope + Ed25519 Signing + Real Manifest — Design

## Context

Subsystem I1 built a working Knowledge Pack pipeline (aggregation, floor check,
loss-honesty invariant, HMAC signing, operator-gated API), but it invented its
own envelope shape (`payload_type: "observation"`, flat payload, `pack_id`
formatted `dkp:charts:obs:YYYY-MM-DD:NNNN`) rather than the ecosystem's actual
contract.

Reading Dot.Brain's own reference repo (`/Users/sakhilebhayi/Dot/Dot.Brain`)
surfaced the real contract: `schemas/knowledge-pack.schema.json`,
`schemas/platform-manifest.schema.json`, and per-payload-type body schemas
(`metric.schema.json`, `insight.schema.json`, `incident.schema.json`,
`recommendation.schema.json`). Two platforms — Dot.Billing and Dot.Emall —
have already completed real onboarding step 1 against this exact contract:
a real Ed25519 keypair, a manifest validated against the schema, and a
hand-run publish command that canonicalizes, signs, and self-verifies before
writing a pack. ChartSense is listed by name in Dot.Brain's own blocker
table as not having done any of this yet.

This sub-project (I2a) reworks I1's foundation to match the real contract,
replacing (not running alongside) the HMAC/flat-observation format. Later
sub-projects (I2b: `insight`, I2c: `incident_report`, I2d:
`recommendation`/outcome) build new payload types on this foundation.

**Note on scope:** this reads and follows conventions from Dot.Brain's
reference repo, but does not modify anything in that repo, register
ChartSense with it, or claim any transport/delivery capability that doesn't
exist. Per Dot.Brain's own documentation, the transport layer (how a
published pack would actually reach a running Dot.Brain instance) is
unbuilt ecosystem-wide — this sub-project produces real, correctly-signed,
schema-shaped artifacts, retrievable via ChartSense's own API, exactly as
far as Dot.Billing and Dot.Emall got.

## Goal

Replace I1's signing/envelope foundation with one that actually validates
against `schemas/knowledge-pack.schema.json` and
`schemas/platform-manifest.schema.json`: a real Ed25519 keypair, RFC-8785-
shaped canonical JSON, a full envelope (contributors, provenance,
confidence, signatures), and loss-honesty preserved via multiple `metric`
payloads per pack rather than one payload with optional fields.

## Key Generation & Storage

- New Artisan command `php artisan dkp:generate-key`:
  - Generates a real keypair via `sodium_crypto_sign_keypair()`.
  - Writes the secret key (base64-encoded, `sodium_crypto_sign_secretkey()`)
    to `storage/app/private/dkp-ed25519.key`.
  - Refuses to overwrite an existing key file (prints an error and exits
    non-zero) — regenerating a key silently would invalidate every
    previously-signed pack's verifiability against the manifest's committed
    public key.
  - Prints the public key (base64-encoded, `sodium_crypto_sign_publickey()`)
    to stdout for the operator to paste into `platform.dkp.json`.
- `key_id`: `dot-charts-dkp-v1` (hard-coded constant, referenced by both the
  manifest and every generated pack's `contributors[].key_id` /
  `signatures[].key_id`).
- `.gitignore` gains `storage/app/private/*.key` (the existing
  `/storage/*.key` rule only matches directly under `storage/`, not the
  nested `storage/app/private/` path).
- `config/services.php` gains `dkp.key_path` defaulting to
  `storage_path('app/private/dkp-ed25519.key')` — overridable via config in
  tests so they can point at a fixture key without touching the real file.

## `knowledge_packs` Table Rework

This migration alters the existing table (from I1) in place — it does not
create a parallel table. Any pack rows present in the local dev database at
migration time are dev-only sqlite data (never real/published), and are
acceptable losses.

**Dropped:** `payload`, `signature`, `signing_key_version`, `period_start`,
`period_end` (period information now lives inside each metric payload's
`observations[].timestamp` and `dimensions`, not as top-level columns).

**Kept:** `id`, `pack_id` (format changes — see below), `payload_type`
(still a flattened convenience column, but now varies per pack: `metric`
today, `insight`/`incident_report`/`recommendation` in later sub-projects),
`strategy_class` (nullable — ChartSense-internal metadata, not part of the
real envelope), `account_count` (nullable), `created_at`.

**Added:** `pack_version` (string, default `1.0.0`), `title` (string),
`summary` (text), `envelope` (json — the complete signed envelope: every
field `knowledge-pack.schema.json` requires).

`pack_id` format changes to `dkp:dot-charts:<uuid>` (matching the schema's
required pattern `^dkp:[a-z0-9-]+:[0-9a-f-]{8}-...-[0-9a-f-]{12}$`) —
`Illuminate\Support\Str::uuid()`.

The `envelope` column is the source of truth for everything the real
schema cares about; the flattened columns exist only so ChartSense's own
list/filter queries don't need to parse JSON for every row.

## Canonicalization & Signing (`App\Services\DkpSigner`)

- `canonicalize(array $envelopeWithoutSignatures): string` — recursive
  key-sort (`ksort` at every array depth) then
  `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
  This is described as "RFC-8785-shaped" (matching Dot.Billing's own
  documented approach) rather than a certified strict-conformance JCS
  implementation — no such PHP package exists in this stack or anywhere
  else in the ecosystem's own shipped code.
- `sign(array $envelope): array` — takes the full envelope, removes the
  `signatures` key, canonicalizes the rest, signs with
  `sodium_crypto_sign_detached($canonical, $secretKey)`, and returns a
  single-element `signatures[]` array:
  `[{key_id: "dot-charts-dkp-v1", algorithm: "ed25519-jcs", signed_at: <iso8601>, value: <base64>}]`.
- `verify(array $envelope): bool` — recomputes the canonical form (again
  excluding `signatures`) and calls
  `sodium_crypto_sign_verify_detached(base64_decode($sig), $canonical, $publicKey)`.
- **Every pack generation calls `verify()` on its own output before
  persisting.** A canonicalization bug or key mismatch throws
  (`RuntimeException: generated pack failed self-verification`) rather than
  writing an unverifiable artifact — matching Dot.Billing's documented
  practice exactly.

## Envelope Content

- **`contributors[]`**: one entry —
  `{id: "chartsense-knowledge-pack-generator", kind: "ai", display_name: "ChartSense Knowledge Pack Generator", key_id: "dot-charts-dkp-v1"}`.
  Honest: every pack is machine-generated, not human-authored.
- **`provenance`**:
  `{sources: [{kind: "system", uri: "chartsense://backtest_runs", observed_at: <period_end ISO8601>}], transformations: [{step: "aggregate_and_sign", tool: "ObservationPackGenerator", tool_version: "2.0.0", actor: "system"}], published_by: "dot-charts"}`.
- **`confidence`** (top-level, 0.0–1.0):
  `min(0.9, 0.5 + max(0, run_count - 50) * 0.001)` — exactly 0.5 at the
  50-run aggregation floor, rising slowly with sample size, capped at 0.9
  (the generator never claims certainty). Deterministic, unit-testable.

## Metric-Body Mapping (loss-honesty via multiple payloads)

`metric.schema.json`'s body holds one scalar per payload — it cannot carry
return, win rate, and drawdown together in one entry the way I1's flat
payload did. `payloads[]` on the envelope accepts multiple entries, so
loss-honesty is preserved by **always publishing exactly 4 metric payloads
together, structurally, with no code path that can emit fewer:**

1. `trading.strategy_mean_return_pct` — unit `percent`, direction `up`
2. `trading.strategy_win_rate_pct` — unit `percent`, direction `up`
3. `trading.strategy_max_drawdown_worst_pct` — unit `percent`, direction `down`
4. `trading.strategy_losing_period_pct` — unit `ratio`, direction `down`

Each payload:
```json
{
  "payload_type": "metric",
  "body": {
    "metric_name": "trading.strategy_max_drawdown_worst_pct",
    "domain": "trading",
    "definition": "Worst single-run max drawdown percentage across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor",
    "unit": "percent",
    "direction_of_good": "down",
    "dimensions": ["strategy_class"],
    "observations": [
      { "timestamp": "2026-08-31T23:59:59Z", "value": -24.58, "dimensions": { "strategy_class": "ma_crossover" }, "sample_size": 55 }
    ]
  }
}
```

A unit test asserts the generator's output always contains exactly these 4
`metric_name`s, regardless of whether the aggregated runs happen to be all
winners (mirroring I1's original loss-honesty test, now expressed as "which
payloads exist" rather than "which fields one payload has").

**Explicitly out of scope for I2a:** registering these `metric_name`s
against the ecosystem's formally-registered domain metrics (the doc's §11:
`trading.signal_hit_rate` etc.) — that requires a working Registry Agent,
which doesn't exist. These names follow the same dot-notation convention
but aren't registered anywhere outside this codebase.

## Real Manifest (`backend/platform.dkp.json`)

Rewritten to satisfy `schemas/platform-manifest.schema.json`'s required
fields exactly (`additionalProperties: false`, so no extra fields):

```json
{
  "platform": "dot-charts",
  "display_name": "Dot.Charts",
  "dkp_version": "1.0.0",
  "endpoints": {
    "publish_topic": "dkp.dot-charts.publish",
    "response_topic": "dkp.dot-charts.response",
    "pr_repository": "git@github.com:sakhilebhayi/ChartSense.git"
  },
  "keys": [
    {
      "key_id": "dot-charts-dkp-v1",
      "algorithm": "ed25519",
      "public_key": "<base64, filled in after running dkp:generate-key>",
      "valid_from": "<iso8601, filled in at generation time>"
    }
  ],
  "advisory_subscriptions": ["all"],
  "rate_limit_per_minute": 100,
  "contacts": [{ "role": "Platform Owner", "handle": "@sakhilebhayi" }]
}
```

`publish_topic`/`response_topic` are documentary placeholders — per
Dot.Brain's own reference documentation, no transport layer exists
anywhere in the ecosystem yet; Dot.Billing and Dot.Emall committed the same
kind of placeholder values in their own real manifests.

`keys[0].algorithm` is `"ed25519"` (the manifest's key-registration
algorithm) — distinct from a pack signature's `"ed25519-jcs"` (which names
the canonicalization scheme used before signing). Both are real schema
requirements, not a typo.

## Rework of Existing I1 Code (replaces, does not run alongside)

- `ObservationPackGenerator`: `buildPayload()` now returns the array of 4
  metric payloads (plus eligibility/account-count as before);
  `generateForPeriod()` assembles the full envelope (contributors,
  provenance, confidence, the 4 payloads), calls `DkpSigner::sign()`, calls
  `DkpSigner::verify()` on its own output, then persists.
- `KnowledgePack` model: `$fillable`/`$casts` updated to the reworked
  columns (`envelope` cast as `array`).
- `KnowledgePackController`: `index` returns lightweight summary fields
  (`id`, `pack_id`, `title`, `payload_type`, `strategy_class`,
  `account_count`, `confidence`, `created_at` — no full `envelope`); `show`
  returns the complete signed envelope.
- `GenerateKnowledgePacks` Artisan command and `routes/console.php`'s
  scheduler entry are unchanged in shape (same signature, same monthly
  call) — only the underlying generator's output format changes.
- `StrategyPerformanceCycleCompleted` event/listener are unchanged — they
  only carry `packId`/`strategyClass`/`accountCount`, none of which change
  meaning.
- `config/services.php`'s `dkp.signing_key` (I1's HMAC secret) and the
  `DKP_SIGNING_KEY` env var are removed, replaced by `dkp.key_path`
  (see Key Generation section) and the real key file.
- **I1's test files are rewritten, not preserved as-is** — every file that
  references the old HMAC config (`services.dkp.signing_key`) or the old
  flat-column shape (`payload`, `signature`, `signing_key_version`) needs
  updating: `ObservationPackGeneratorTest`,
  `ObservationPackGeneratorSigningTest`, `PlatformManifestTest`,
  `GenerateKnowledgePacksCommandTest`, `KnowledgePackControllerTest`
  (assertions no longer hold once the envelope format changes), plus
  `StrategyPerformanceCycleEventTest` (drop its now-unused
  `config(['services.dkp.signing_key' => ...])` setup line — the event
  itself is otherwise unaffected) and `KnowledgePackTest` (its
  `KnowledgePack::create()` fixture uses the old columns and must be
  updated to the reworked schema).

## Testing Plan

- `DkpSignerTest`: canonicalization determinism (same logical content,
  different key insertion order, produces identical canonical string),
  sign→verify round-trip, tamper detection (mutating any envelope field
  after signing fails verification).
- `GenerateDkpKeyCommandTest`: creates the key file with correct
  permissions/content shape, refuses to overwrite an existing file, prints
  a public key that round-trips through `DkpSigner::verify()`.
- `ObservationPackGeneratorTest` (rewritten): floor check unchanged from
  I1, full envelope has every field `knowledge-pack.schema.json` requires,
  **exactly 4 metric payloads always present** with the exact 4
  `metric_name`s (the new loss-honesty structural test), confidence formula
  correctness at the floor and above it, contributors/provenance shape
  correctness, self-verification passes on generator output.
- `PlatformManifestTest` (rewritten): every field
  `platform-manifest.schema.json` requires is present and correctly typed,
  `keys[0].algorithm === "ed25519"`, `keys[0].public_key` matches the
  public key derived from the configured secret key file.
- `KnowledgePackControllerTest` (rewritten): operator-gating behavior
  unchanged from I1 (401/403 paths identical); `index` omits `envelope`;
  `show` returns the full envelope including `payloads[]` and
  `signatures[]`; a fetched pack's signature independently verifies via
  `DkpSigner::verify()`.
- `GenerateKnowledgePacksCommandTest` (rewritten): same command-behavior
  assertions as I1, now checking envelope shape instead of flat payload
  shape.

## Explicitly Out of Scope for I2a

- `insight`, `incident_report`, `recommendation` payload types (I2b/I2c/I2d).
- Registering ChartSense with a real running Dot.Brain instance (onboarding
  step 2+) — no registry infrastructure exists anywhere in the ecosystem.
- Any real transport/delivery of a generated pack to Dot.Brain — no
  transport layer exists ecosystem-wide, confirmed by Dot.Brain's own
  documentation.
- A certified, strictly-conformant RFC 8785 implementation — "RFC-8785-
  shaped" canonicalization matches the ecosystem's own established
  precedent (Dot.Billing, Dot.Emall), not a stricter standard neither of
  those platforms met either.
