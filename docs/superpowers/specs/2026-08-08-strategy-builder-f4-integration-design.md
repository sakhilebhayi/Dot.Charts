# Strategy Builder — F4: Integration — Design

**Subsystem:** F (from the Dot.Charts gap-closure audit), sub-project 4 of 4 (final)

## Problem

F1 (rule engine), F2 (persistence), and F3 (builder UI) are complete, but
saved custom strategies are only reachable through `strategy-builder.html`'s
own Test Run button. They don't appear in `backtest.html`'s strategy
dropdown — the same place every one of the 5 built-in presets is actually
run from — and `history.html`'s strategy filter has no way to isolate
custom-strategy runs. F4 closes this final gap.

## Scope

Recap of the 4-part decomposition established in F1's spec: F1 (done), F2
(done), F3 (done), **F4 (this spec, final)** — integration into
`backtest.html`'s dropdown and `history.html`'s filter.

## Dropdown Integration

`backtest.js`, on page load, if the user is logged in
(`isLoggedIn()` from `auth.js` — same gating F3 already uses for its Save
button and Load-from-saved dropdown): calls `GET /api/strategies` and, for
each saved strategy, appends `<option value="custom:{id}">{name}</option>`
to the existing `#strategy` `<select>`, alongside the 5 hardcoded built-in
`<option>`s already there. Logged-out users see only the 5 built-ins — the
fetch is skipped entirely, unchanged from today.

A module-level `savedStrategyRules` map (`{id: rules}`) is built from that
same fetch response. The run-payload builder (currently
`strategy: document.getElementById('strategy').value, params: {}` for
every strategy) changes to: if the selected `<option>`'s value starts with
`custom:`, extract the id, send `strategy: "custom"` and
`params: savedStrategyRules[id]`; otherwise send the selected value as
`strategy` and `params: {}`, exactly as today. This is the same
`/api/backtests` call every existing strategy already makes — no new
endpoint, no change to the analytics service.

## Re-run Compatibility

`history.js`'s existing `rerun(run)` function already stores
`run.params` (a custom run's actual rule JSON) into `sessionStorage`
alongside `strategy: "custom"` — this already works today, it's just that
`backtest.js`'s prefill logic never reads `prefill.params`. That exact rule
JSON might not match any of the user's *currently* saved strategies (they
may have edited-and-saved-as-new, or deleted the original since).

Fix: when `backtest.js`'s existing re-run prefill block sees
`prefill.strategy === 'custom'`, it appends one more `<option
value="custom:rerun">Custom (from history)</option>` to the dropdown,
sets `savedStrategyRules['rerun'] = prefill.params`, and selects that
option. Re-run then keeps working with the *exact* rules that produced the
original result, completely independent of whatever the saved-strategies
list currently contains — matching how re-run already works for every
built-in strategy (it just re-sends what was actually run).

## History Filter

`history.html`'s strategy filter `<select>` gains one static option:
`<option value="custom">Custom Strategy</option>`, placed alongside the 5
existing built-in options. This matches `backtest_runs.strategy = "custom"`
exactly — filtering shows every custom-strategy run together,
undifferentiated by which specific named strategy produced each one (that
would need a new `backtest_runs.custom_strategy_id` column linking a run
back to the `custom_strategies` row that produced it — a reasonable future
enhancement, explicitly out of scope here). `history.js`'s row rendering
already shows the raw `run.strategy` value (`"custom"`) in its meta line —
no change needed there.

## Testing

Manual-browser-verified only, consistent with F3 and every other frontend
page in this codebase — no JS test framework exists to extend.
Verification:
- Log in, save 1-2 strategies via `strategy-builder.html` (already proven
  working in F3), open `backtest.html`, confirm both appear in the
  dropdown alongside the 5 built-ins.
- Select a saved custom strategy, run it against real data, confirm
  results match what Test Run produced for the same rules in F3.
- Log out, reload `backtest.html`, confirm only the 5 built-ins are
  present (no custom options, no failed fetch visible to the user).
- From `history.html`, re-run a saved custom-strategy backtest, confirm
  `backtest.html` opens with a "Custom (from history)" option selected and
  running it reproduces the same result.
- From `history.html`, filter by "Custom Strategy", confirm only
  custom-strategy runs appear in the list.
