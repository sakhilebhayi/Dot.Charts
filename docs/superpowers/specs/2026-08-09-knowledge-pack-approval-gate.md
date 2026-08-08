# Dot.Charts: Knowledge Pack Approval Gate (First Real Level 2 Process)

## Context

Dot.Charts' autonomy classification audit (`Dot.Brain/platforms/dot-charts.md`, 2026-08-08) found a real Level 1 process (the monthly observation-pack generation cycle) but no real Level 2 process — its own gap summary named the exact fix needed: *"the repo would need an actual human-approval queue (a pending/approved/rejected state and endpoint) wired to RecommendationPackGenerator's output, since none exists today."*

Direct inspection of the real codebase (`~/Dot/ChartSense`) confirms this precisely. `KnowledgePack` (`backend/app/Models/KnowledgePack.php`) has no `status` column at all. `RecommendationPackGenerator::generate()`, `InsightPackGenerator::generate()`, and `IncidentPackGenerator::generate()` (`backend/app/Services/*PackGenerator.php`) each build an envelope, call `$this->signer->sign($envelope)` **inline during construction**, verify it, and immediately call `KnowledgePack::create()` — the pack is a permanently signed, "official" ecosystem artifact the instant the generator runs, with zero human review step in between. All three commands (`dkp:generate-recommendation`, `dkp:generate-insight`, `dkp:generate-incident`) are manually invoked (not scheduled), but "a human ran the command" and "a human reviewed and approved this specific claim" are not the same thing — the generator can act on stale or wrong evidence with nobody checking the actual proposal before it's signed as authoritative.

The monthly **observation** pack cycle (`ObservationPackGenerator`) is explicitly out of scope: it's pure aggregated fact-reporting (mean return, win rate, drawdown, losing-period rate) with a structural n≥50 floor and no interpretive claim being made — the audit correctly classified it Level 1, and this spec does not change that.

## Goal

Recommendation, Insight, and Incident packs — the three payload types that make an interpretive claim rather than report a raw aggregate — go through a real `pending_approval → approved | rejected` gate before they are signed and treated as an official Knowledge Pack. Approval is restricted to platform operators (the existing `is_platform_operator` gate already used for every `knowledge-packs/*` route). Rejection requires a reason.

## Changes

### 1. `status` column on `knowledge_packs`

Migration adds: `status` (string, default `'approved'` — existing rows and all future `observation`-type packs stay unaffected, never entering the gate), `rejected_reason` (nullable text), `reviewed_by` (nullable, references `users.id`), `reviewed_at` (nullable timestamp). Added to `KnowledgePack::$fillable`.

### 2. Generators create packs as `pending_approval`, unsigned

`RecommendationPackGenerator::generate()`, `InsightPackGenerator::generate()`, `IncidentPackGenerator::generate()` each change in the same way: build the envelope with `'signatures' => []` as today, but **skip** the `$this->signer->sign($envelope)` / `verify()` calls at generation time — there is nothing real to sign yet, since nobody has approved the claim. `KnowledgePack::create()` gets `'status' => 'pending_approval'` added to its argument array; the `envelope`'s `signatures` array stays empty until approval. Return shape gains a `pending_approval: true` flag so callers (the artisan commands' console output) can report the pack is awaiting review, not published.

### 3. `KnowledgePackApprovalService`

New service, `backend/app/Services/KnowledgePackApprovalService.php`, reusing the existing `DkpSigner`:

- `approve(KnowledgePack $pack, User $reviewer): KnowledgePack` — refuses (throws `RuntimeException`) unless `$pack->status === 'pending_approval'`. Re-signs the pack's stored envelope via `$this->signer->sign($envelope)`, verifies it (`RuntimeException` on failure, same self-verification discipline every generator already uses), updates the pack's `envelope` (now carrying real signatures), sets `status = 'approved'`, `reviewed_by = $reviewer->id`, `reviewed_at = now()`.
- `reject(KnowledgePack $pack, User $reviewer, string $reason): KnowledgePack` — refuses unless `$pack->status === 'pending_approval'`. Refuses an empty/whitespace-only `$reason` (`RuntimeException`). Sets `status = 'rejected'`, `rejected_reason = $reason`, `reviewed_by = $reviewer->id`, `reviewed_at = now()`. The envelope stays unsigned — a rejected pack is permanently inert, never counted as a real published Knowledge Pack by anything that reads `knowledge_packs`.

### 4. `KnowledgePackController` — approval endpoints

New methods, gated by the existing `operator` middleware group in `backend/routes/api.php` (same group as every other `knowledge-packs/*` route):

- `GET /knowledge-packs/pending` → `pendingApprovals()`: lists `status = 'pending_approval'` packs, same response shape as `index()` plus the unsigned envelope's `body` field so a reviewer can actually read the proposal/finding before deciding.
- `POST /knowledge-packs/{id}/approve` → `approve()`: calls `KnowledgePackApprovalService::approve()`, returns the finalized pack's `pack_id` and `status`.
- `POST /knowledge-packs/{id}/reject` → `reject()`: validates `reason` as `required|string`, calls `KnowledgePackApprovalService::reject()`, returns the pack's `status` and `rejected_reason`.

`index()` gains a `status` field in its response array (so callers can see what's approved vs. pending vs. rejected) and an optional `?status=` query filter (defaults to no filter — returns all, since operators reviewing the full history is the common case for this endpoint, and `pending` already exists as a dedicated view for the review queue specifically).

## Testing

Existing Pest/PHPUnit conventions in `backend/tests/` (matching prior DKP work's test files) — feature/unit tests covering: each of the three generators creates a `pending_approval` pack with an unsigned envelope (`signatures === []`); `KnowledgePackApprovalService::approve()` on a pending pack signs it, verifies, and flips status to `approved`; `approve()` on an already-approved or rejected pack throws; `reject()` requires a non-empty reason and refuses an empty one; `reject()` on a pending pack sets `rejected` with the reason and leaves the envelope unsigned; the API endpoints round-trip through the `operator` middleware (a non-operator gets denied, matching the existing pattern already tested for `generate`/`index`/`show`/`ingestCheck`).

## Explicitly out of scope

- `ObservationPackGenerator` / the monthly observation-pack cycle — stays Level 1, untouched, no gate.
- Any UI beyond the three new/modified JSON API endpoints — this repo has no Livewire/web UI for knowledge packs today, only API routes; this spec doesn't add one.
- Re-signing or re-verifying already-`approved` (pre-existing, migrated-as-`approved`) packs — the migration's default value handles backward compatibility without touching their envelopes.
- Notifying an operator that a pack is pending review (no email/notification channel exists for this in the codebase today) — an operator finds pending packs via `GET /knowledge-packs/pending`, not a push notification.
- Registering this change in Dot.Brain's `platforms/dot-charts.md` or `platforms/autonomy-signals.json` — a separate, future re-audit pass, not part of building the feature.
