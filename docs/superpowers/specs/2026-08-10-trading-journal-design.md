---
title: Trading Journal — Design
version: 1.0.0
status: approved
owners: [Charts Platform Lead]
last-review: 2026-08-10
---

# Trading Journal — Design

## Purpose

Add a trading journal: a place for a user to write reflective notes about
their backtests and strategies, optionally linked to a specific
`BacktestRun` or `CustomStrategy`, or standing alone. Closes the "Build the
trading journal, with the position/order exclusion enforced at the schema
level from day one" item that has been open on `wiki.md`'s roadmap since
the 0.1.0 review.

**Related documents:**
- [ChartSense's own wiki.md](../../wiki.md) — §3 Domain Entities lists
  "Trading journal entry" as `planned`; §7 Compliance Posture states the
  position/order exclusion this design must uphold
- `platforms/dot-charts.md` (Dot.Brain, `~/Dot/Dot.Brain`) §2 — "Position /
  order (operational) — Never graphed. User positions are the platform's
  most sensitive data — the HR-exclusion pattern applied to financial
  holdings"

## Why this slice, why now

Dot.Charts is the ecosystem's only regulated-market platform and has held,
consistently, that it will never persist a user's real positions or
orders — not as an oversight to fix later, but as a permanent, deliberate
exclusion (matching the HR-exclusion pattern for sensitive data
elsewhere in the ecosystem). A conventional "trading journal" (entry
price, exit price, position size, P&L) would violate that invariant
directly. This design instead makes the journal a reflection tool grounded
in what Dot.Charts actually computes — its own backtest results and saved
strategies — never a log of real trades.

## Scope for this slice

**In scope:**
- One new table, `journal_entries`: `title`, `body`, optional `symbol`,
  optional link to a `BacktestRun`, optional link to a `CustomStrategy`.
  Owner-scoped, full CRUD.
- `JournalEntryController` (`store`/`index`/`show`/`update`/`destroy`),
  matching `CustomStrategyController`'s ownership pattern (404, not 403, on
  another user's entry).
- Ownership validation on the two optional links: linking to a
  `backtest_run_id`/`custom_strategy_id` that doesn't belong to the
  authenticated user is rejected, not just checked for existence.
- Frontend: new `journal.html` + `journal.js` page (list, create/edit form,
  delete), nav link added across existing pages, and a "+ Journal"
  quick-action per row on `history.html`'s backtest list that opens the
  create form with `backtest_run_id`/`symbol` prefilled.
- A regression test asserting the `journal_entries` migration never gains
  a position/order-shaped column (`quantity`, `price`, `entry_price`,
  `exit_price`, `side`, `position_size`, or similar) — the schema-level
  enforcement the roadmap item specifically asked for.

**Explicitly out of scope (YAGNI, not because they're wrong ideas):**
- Any position/order/P&L field, ever, on this or any future table for this
  feature. Not just "not now" — genuinely never, per the standing platform
  invariant. This is the one line item in this design that should not be
  revisited without an explicit, deliberate ecosystem-level decision to
  change Dot.Charts's regulated-market posture.
- Mood/sentiment tracking, tags, or any structured psychology fields —
  `title` + `body` is freeform text; a richer taxonomy can be added later
  if the lean version turns out to need it.
- A self-reported "outcome" enum (as-expected / better / worse) — considered
  and deferred; can be added as a nullable column later without a breaking
  change if wanted.
- Attachments (e.g. linking an uploaded chart image) — no existing
  infrastructure for user-uploaded persistent images in this app; separate
  slice if wanted.
- Full-text search — `?symbol=` / `?backtest_run_id=` / `?custom_strategy_id=`
  filters on the list endpoint cover the realistic v1 use cases.

## Architecture

### Data model

New migration, `journal_entries`:

| Column | Type | Notes |
|---|---|---|
| `id` | | |
| `user_id` | `foreignId` → `users` | owner, `cascadeOnDelete` |
| `title` | `string` | required |
| `body` | `text` | required |
| `symbol` | `string`, nullable | free text, matching how `backtest.html`/`strategy-builder.html` already accept symbols (no validated instrument registry anywhere in this app) |
| `backtest_run_id` | `foreignId` → `backtest_runs`, nullable | `nullOnDelete` — a journal entry should survive its linked backtest run being deleted, just losing the link |
| `custom_strategy_id` | `foreignId` → `custom_strategies`, nullable | `nullOnDelete`, same reasoning |
| `created_at` / `updated_at` | | |

`App\Models\JournalEntry`: `belongsTo(User::class)`,
`belongsTo(BacktestRun::class)`, `belongsTo(CustomStrategy::class)`
(the latter two implicitly nullable via the nullable FK).

### Backend API

`App\Http\Controllers\JournalEntryController`, registered under the
existing `auth:sanctum` group in `routes/api.php` alongside
`/backtests` and `/strategies`:

| Method | Route | Behavior |
|---|---|---|
| `POST` | `/api/journal-entries` | validates `title`/`body` required, `symbol` nullable string, `backtest_run_id`/`custom_strategy_id` nullable integers; if either link is present, a manual ownership check (`BacktestRun::where('id', ...)->where('user_id', $request->user()->id)->exists()`, same shape for `CustomStrategy`) runs before create — a bare `exists:backtest_runs,id` Laravel rule would accept *any* user's backtest run, not just the caller's |
| `GET` | `/api/journal-entries` | owner-scoped, paginated at 20 (matching `GET /api/backtests` and `GET /api/strategies`), optional `?symbol=`, `?backtest_run_id=`, `?custom_strategy_id=` filters, newest first |
| `GET` | `/api/journal-entries/{id}` | owner-scoped `firstOrFail` |
| `PATCH` | `/api/journal-entries/{id}` | same validation as `store`, owner-scoped |
| `DELETE` | `/api/journal-entries/{id}` | owner-scoped |

### Frontend

- `journal.html` + `journal.js`: paginated list (title, symbol badge if
  present, linked-backtest/strategy badge if present, created date),
  create/edit form (title, body, symbol, a dropdown for "link to a saved
  strategy" populated from `GET /api/strategies` and a dropdown for "link
  to a past backtest" populated from `GET /api/backtests`, both optional
  and defaulting to "none"), delete action. Auth-gated the same way
  `backtest.js`/`strategy-builder.js` already redirect to `/login.html`
  when `!isLoggedIn()`.
- Nav link ("Journal") added across `index.html`, `backtest.html`,
  `history.html`, `strategy-builder.html`, `journal.html` itself — matching
  how the Strategy Builder link was rolled out everywhere in an earlier
  slice.
- `history.html`: a small "+ Journal" action per backtest-run row, linking
  to `journal.html?backtest_run_id=<id>&symbol=<symbol>` — `journal.js`
  reads those query params on load and prefills the create form.

## Error handling

- `422` for validation failures (missing `title`/`body`, malformed link
  IDs) — standard Laravel `$request->validate()` path, same as every other
  controller in this app.
- `422` (not `403`, and not a silent `null` link) when a `backtest_run_id`
  or `custom_strategy_id` is supplied but doesn't belong to the
  authenticated user — this is a validation failure on the request, not an
  authorization failure on an existing resource, since the entry being
  created doesn't exist yet.
- `404` for `show`/`update`/`destroy` on another user's entry or a
  nonexistent one — no distinction between the two, matching
  `CustomStrategyController`'s existing pattern (avoids leaking whether an
  ID exists).

## Testing

- `JournalEntryControllerTest` (feature): full CRUD happy path; validation
  failures (missing title/body); linking to your own backtest run/strategy
  succeeds; linking to *another user's* backtest run/strategy is rejected
  with `422`; `show`/`update`/`destroy` on another user's entry returns
  `404`; pagination; each of the three list filters.
- `tests/Unit/JournalEntriesSchemaInvariantTest.php` — a schema-invariant
  regression test (Unit, not Feature: it inspects DB schema directly via
  `Schema::getColumnListing('journal_entries')`, no HTTP layer involved,
  matching how this app already places schema/logic-only tests like
  `DkpSignerTest`/`KnowledgePackApprovalServiceTest` under `tests/Unit`)
  that fails if any banned position/order-shaped name appears — the actual
  mechanism proving the roadmap item's "enforced at the schema level"
  claim, not just a comment saying so.
- Frontend: manual verification (matches this project's existing
  convention — no JS test framework anywhere in it) — create/edit/delete
  an entry, confirm the History quick-link prefills correctly, confirm the
  nav link appears on every page.

## Open questions

None blocking. If a self-reported outcome field or tagging turns out to be
wanted after real use, both can be added as new nullable columns without a
breaking change — deferred deliberately, not forgotten.

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 1.0.0 | 2026-08-10 | Charts Platform Lead (brainstorming session) | Initial design: journal entries with optional links to `BacktestRun`/`CustomStrategy`, full CRUD, position/order exclusion enforced by schema omission plus a regression test, new `journal.html` page with a quick-link from `history.html` |
