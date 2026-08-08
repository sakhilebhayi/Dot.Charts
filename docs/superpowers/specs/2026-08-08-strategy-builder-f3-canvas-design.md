# Strategy Builder — F3: Visual Builder UI — Design

**Subsystem:** F (from the Dot.Charts gap-closure audit), sub-project 3 of 4

## Problem

F1 (rule model + execution engine) and F2 (persistence) are complete, but
there is no UI to actually build a strategy — every rule so far has been
constructed by hand as JSON. F3 adds the visual builder page.

## Scope

Recap of the 4-part decomposition established in F1's spec:
1. F1 — rule model + execution engine (done).
2. F2 — persistence: save/load/list/delete (done).
3. **F3 (this spec)** — the visual builder UI.
4. F4 — integration into `backtest.html`'s strategy dropdown, History, etc.

## Canvas Approach

wiki.md's long-term vision describes a "visual strategy builder," which
could be read as a literal node-graph canvas (draggable nodes wired
together with connectors, like a flowchart tool). F1's rule model is
deliberately **flat** — each of `entry`/`exit` is one list of conditions
plus a single AND/OR applied across all of them, not a nested tree — so a
true node-graph UI would either visually imply branching/nesting the
backend can't express, or need constraining to a shape that undersells
the "wires" metaphor.

**F3 builds a structured, visual, drag-driven builder instead of a literal
node-graph**: two panels (Entry / Exit), each holding draggable condition
cards with an ALL/ANY toggle, not a canvas of connected nodes. This is
still genuinely "build your strategy by dragging things" — condition
cards support drag-to-reorder — but honestly represents what the flat
rule model can express, with no wires implying capabilities that don't
exist.

## Layout

Two panels side-by-side (Entry left, Exit right), each independently
scrollable if needed. Each panel header shows an ALL/ANY toggle
(dropdown) for that panel's `combinator`. Below the toggle, a list of
condition cards; each card is one inline row:

```
[left operand type ▾] [left params...] [comparator ▾] [right operand type ▾] [right params...] [✕]
```

- Operand type dropdown: `Close`, `Open`, `High`, `Low`, `Volume`, `EMA`,
  `SMA`, `RSI`, `ATR`, `Bollinger Upper`, `Bollinger Mid`,
  `Bollinger Lower`, `Value`. Selecting an indicator that needs
  parameters (`length`, and `std` for the three Bollinger options) shows
  the matching number input(s) next to it; selecting `Value` shows a
  single plain number input instead of indicator params — this is the
  operand's `{"indicator": ..., ...}` vs `{"value": ...}` distinction
  from F1's schema, expressed as a UI toggle rather than two separate
  widgets.
- Comparator dropdown: `crosses above`, `crosses below`, `greater than`,
  `less than` — F1's four comparators verbatim.
- A drag handle (native HTML5 Drag and Drop API — `draggable="true"` +
  `dragstart`/`dragover`/`drop` handlers, no new library) lets a card be
  reordered within its panel. This is purely cosmetic — combinator
  application is order-independent for a flat AND/OR list — included for
  visual/organizational value, not because order affects evaluation.
- "+ Add Condition" appends a new blank card to the panel.

## Test Run

Below the two panels: a Test Run section with `Symbol`, `Asset class`,
`Start date`, `End date` inputs and a "Test Run" button. Clicking it
builds `{entry: {...}, exit: {...}}` from the current panel state and
POSTs to the **existing** `/api/backtests` endpoint with
`strategy: "custom"` and that object as `params` — the exact contract F1
already built and verified, with no new backend work needed. Results
render using the **existing shared** `results-renderer.js` (already used
by both `backtest.html` and `history.html`), so Test Run's results look
identical to a regular backtest run's. Matches `/api/backtests`'
existing anonymous-allowed-but-rate-limited behavior — Test Run works
without login, same as every other strategy on `backtest.html`.

## Save & Load

- **Save**: a `Name` text field (optional `Description`) plus a "Save
  Strategy" button, visible/enabled only when logged in (hidden/disabled
  with a "Log in to save" hint otherwise, reusing the existing `auth.js`
  module's login-state pattern every other page already uses). POSTs to
  `POST /api/strategies` with the current rule JSON.
- **Load from saved**: a dropdown populated from `GET /api/strategies`
  (only shown/populated when logged in) lets a user pick an existing
  strategy; selecting one calls `GET /api/strategies/{id}` and
  repopulates both panels from its `rules`. Since F2 has no update
  endpoint, saving afterward always creates a **new** strategy — this is
  a convenience starting point ("build from a template"), not in-place
  editing.

## Error Handling

- **Test Run failure** (non-2xx from `/api/backtests` — a genuine
  data-fetch error, since the UI's own dropdowns make a malformed rule
  hard to construct): displayed inline where results would render,
  matching `backtest.js`'s existing error-display pattern.
- **Save validation**: a client-side check blocks the Save button if
  either panel has zero conditions, mirroring `evaluate_rule`'s own
  "must have at least one condition" requirement — failing fast in the
  UI instead of round-tripping to get a 422. A real 422 from
  `POST /api/strategies` (e.g., an edge case the client-side check
  doesn't catch) displays the server's error message inline near the
  Save button.

## Navigation

`index.html`, `backtest.html`, and `history.html` each gain a "Strategy
Builder" nav link, matching the existing `→ Run a real backtest` /
`History` link pattern already present on those pages.

## Testing

This page is manual-browser-verified only — there is no JS unit-test
framework anywhere in this codebase to extend, consistent with every
other frontend page built this session. Verification: build a real
two-condition entry rule and a one-condition exit rule through the UI,
drag to reorder a condition, click Test Run against real market data and
confirm results render via the shared renderer, save the strategy while
logged in and confirm it's retrievable via a direct `GET /api/strategies`
check, reload the page, and use "Load from saved" to confirm the
conditions repopulate correctly from the persisted rule JSON.
