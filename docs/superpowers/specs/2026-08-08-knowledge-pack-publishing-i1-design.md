# Subsystem I1: Knowledge Pack Publishing (Observation Packs, Outbound Only) — Design

## Context

ChartSense/Dot.Charts is meant to publish signed "Knowledge Packs" (DKP) that
the Dot ecosystem's knowledge layer, Dot.Brain, ingests. The authoritative
spec lives in the (separate) Dot.Brain repo at `platforms/dot-charts.md` and
describes 4 payload types (`observation`, `insight`, `outcome`, `incident`),
a bidirectional MNPI compliance gate, a signed `platform.dkp.json` manifest,
an aggregation floor of n≥50 accounts for observation packs, and 3 emitted
events. None of this exists in ChartSense today — `wiki.md` §5 and §8
confirm "not implemented" across the board.

Dot.Brain's own integration model is file/PR-mediated, not a live HTTP API —
there is no ingestion endpoint anywhere in the spec. "Publishing" therefore
means generating correctly-shaped, signed artifacts that are retrievable, not
making a network call to a running service.

This is the first sub-project of Subsystem I. It intentionally covers a
narrow, provable slice: **outbound generation of `observation` packs only.**
The other 3 payload types, the inbound half of the compliance gate, dual-
control review, the append-only audit log, and real delivery to Dot.Brain
are out of scope here and will be later sub-projects (I2+).

## Goal

Generate schema-valid, signed `observation` Knowledge Pack JSON artifacts
from real ChartSense backtest data, aggregated across distinct users per
strategy class, on a monthly schedule, retrievable via an admin-gated API.

## Data Model

### `users.is_platform_operator`

New boolean column, default `false`. Named to avoid the generic, easily
targeted `is_admin`/`admin` naming. **Not** added to `User::$fillable` —
excluded from mass assignment entirely, so no request body (registration,
profile update, or otherwise) can ever set it. Only settable via `tinker`,
a seeder, or direct DB access. A regression test asserts `POST /register`
with `is_platform_operator: true` in the body is silently ignored and the
resulting user has it `false`.

### `knowledge_packs` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `pack_id` | string, unique | e.g. `dkp:charts:obs:2026-08-01:0001` |
| `payload_type` | string | `observation` only, for now |
| `strategy_class` | string | e.g. `ma_crossover`, `custom` |
| `period_start` | date | first day of the aggregated month |
| `period_end` | date | last day of the aggregated month |
| `account_count` | integer | distinct users backing this pack |
| `payload` | json | full pack body (see below) |
| `signature` | string | hex HMAC-SHA256 digest |
| `signing_key_version` | string | `v1` |
| `created_at` | timestamp | |

`updated_at` is intentionally omitted — packs are immutable once generated
(append-only in spirit, matching the ecosystem's audit-log principle even
though the full audit-log mechanism itself is deferred).

Backed by an Eloquent model `App\Models\KnowledgePack` (standard
convention matching `BacktestRun`/`CustomStrategy` elsewhere in this
codebase) — this is the class referenced throughout the rest of this doc
(e.g. the `verify()` helper in Signing).

## Aggregation Query & Loss-Honesty Payload

A new `App\Services\ObservationPackGenerator` service, given a
`strategy_class` and a period (defaults to the previous calendar month):

1. Query `backtest_runs` where `status = 'complete'`, `strategy = strategy_class`
   (or, for `custom`, all rows with `strategy = 'custom'` — custom strategies
   are aggregated as one class, not per-name, matching how History's filter
   already treats them per Subsystem F4), and `created_at` within the period.
2. Group by `user_id` to compute `account_count` (distinct users).
3. **Floor check:** if `account_count < 50`, no pack is generated for that
   strategy/period. This is logged (`Log::info`) but is not an error —
   callers (the scheduler and the manual endpoint) receive a "below floor,
   not generated" result rather than a pack.
4. If the floor is met, compute the aggregate payload:

```json
{
  "pack_id": "dkp:charts:obs:2026-08-01:0001",
  "payload_type": "observation",
  "strategy_class": "ma_crossover",
  "period_start": "2026-08-01",
  "period_end": "2026-08-31",
  "account_count": 54,
  "run_count": 211,
  "mean_return": 0.083,
  "median_return": 0.061,
  "win_rate": 0.57,
  "max_drawdown_p50": -0.114,
  "max_drawdown_worst": -0.312,
  "losing_period_count": 87,
  "losing_period_pct": 0.412,
  "generated_at": "2026-09-01T01:00:00Z"
}
```

**Loss-honesty is a structural invariant, not a configurable option.** The
generator's payload-building code always computes and includes
`max_drawdown_p50`, `max_drawdown_worst`, `losing_period_count`, and
`losing_period_pct` — there is no parameter or code path that omits them.
A unit test builds a payload from a fixture where every included run is a
winner and asserts the output still carries non-null, correctly-computed
`losing_period_pct: 0.0` (proving the field is structural, not
conditionally present only when losses exist) alongside a separate fixture
with a realistic loss mix asserting non-zero values are computed correctly.

## Signing

- Canonical JSON: payload keys sorted, compact separators (no incidental
  whitespace variance across generations of the same logical content).
- `signature = hash_hmac('sha256', canonical_json, env('DKP_SIGNING_KEY'))`.
- `signing_key_version` is hard-coded to `"v1"` — matches the doc's
  `vault://keys/dot-charts/dkp-signing/v1` naming convention without a real
  secrets vault (none exists in this codebase; `DKP_SIGNING_KEY` follows the
  existing `.env`-based-secrets pattern used everywhere else).
- A `verify(KnowledgePack $pack): bool` helper recomputes and
  `hash_equals()`-compares the signature — used by tests now, exposed for a
  future consumer (e.g. an I2+ delivery step) to validate integrity later.

## Manifest

`backend/platform.dkp.json` — a static, checked-in file (not dynamically
generated per-request), analogous to Dot.Brain's own static
`platforms/dot-charts.md`:

```json
{
  "platform": "dot-charts",
  "version": "0.1.0",
  "publishes": ["observation"],
  "subscribes": [],
  "default_classification": "restricted",
  "signing_key": "vault://keys/dot-charts/dkp-signing/v1",
  "tenancy": {
    "aggregation_floor": 50
  }
}
```

`publishes` lists only `observation` until later sub-projects add the other
3 types. `subscribes` is empty — inbound consumption of Brain recommendations
is out of scope for I1.

## Scheduling

Laravel's built-in scheduler, in `app/Console/Kernel.php`:

```php
$schedule->call(function () {
    foreach (ObservationPackGenerator::knownStrategyClasses() as $class) {
        app(ObservationPackGenerator::class)->generateForPreviousMonth($class);
    }
})->monthlyOn(1, '01:00');
```

Backed by a single Artisan command,
`php artisan knowledge-packs:generate {strategy_class} {--period=}`
(period defaults to the previous calendar month, format `YYYY-MM`), which
both the scheduler and the manual admin endpoint call — one code path, not
two. Requires the standard Laravel cron entry
(`* * * * * php artisan schedule:run`), documented in the plan as a manual
deployment step (not automatable from within this repo).

## Events

A real Laravel event, `App\Events\StrategyPerformanceCycleCompleted`
(`pack_id`, `strategy_class`, `account_count`), dispatched after a pack is
successfully generated. One listener logs it:
`Log::info('trading.strategy.performance_cycle', [...])`. This satisfies
"events emitted" per the ecosystem spec's naming convention without
introducing a message bus or queue infrastructure that doesn't exist
anywhere else in this codebase — a later sub-project can add real
subscribers without changing this event's shape.

## API

All three endpoints sit behind `auth:sanctum` plus a new `operator`
middleware/gate checking `is_platform_operator`:

- `POST /api/knowledge-packs/generate` — body `{ "strategy_class": "...", "period": "YYYY-MM" }`
  (`period` optional, defaults to previous month). Runs the same generation
  logic as the Artisan command. Returns the created pack (minus signature
  material duplication concerns — signature is fine to return, it's not a
  secret) on success, or `{ "generated": false, "reason": "below_floor", "account_count": N }`
  with a 200 if the floor wasn't met.
- `GET /api/knowledge-packs` — paginated list: `id`, `pack_id`,
  `strategy_class`, `period_start`, `period_end`, `account_count`,
  `created_at`. No full `payload`/`signature` in the list view.
- `GET /api/knowledge-packs/{id}` — full record including `payload` and
  `signature`.
- Non-operator authenticated users get `403`; unauthenticated get `401` —
  matching existing Sanctum-guard conventions elsewhere in this codebase.

## Testing Plan

- `ObservationPackGeneratorTest`: floor check (49 vs 50 distinct users),
  aggregate math correctness against a hand-computed fixture, loss-honesty
  structural invariant (both fixtures described above), `custom` strategy
  aggregation (grouped as one class), idempotency (`pack_id` uniqueness —
  re-running generation for an already-generated period doesn't duplicate).
- Signing: sign-then-verify round-trip; verify fails on tampered payload.
- `is_platform_operator` mass-assignment regression test.
- Feature tests for all 3 endpoints: operator success paths, non-operator
  403, unauthenticated 401, below-floor response shape.
- Scheduler: unit test that the registered schedule entry exists and calls
  the expected command (not a real month-boundary integration test — no
  precedent for time-travel testing elsewhere in this codebase).

## Explicitly Out of Scope for I1 (deferred to I2+)

- `insight`, `outcome`, `incident` payload types.
- Inbound MNPI screening / classification-floor verification.
- Dual-control review workflow, append-only audit log as a first-class
  feature (beyond the immutability convention noted above).
- Real asymmetric signing / secrets vault.
- Actual network delivery to Dot.Brain — no ingestion endpoint exists to
  deliver to; this slice stops at "artifact exists, signed, retrievable via
  API."
- Per-account (rather than per-strategy-class) breakdowns in the payload —
  would risk re-identification at low account counts even above the floor;
  not requested by the spec's example payload shape.
