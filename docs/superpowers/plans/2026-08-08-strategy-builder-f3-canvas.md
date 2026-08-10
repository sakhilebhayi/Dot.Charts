# Strategy Builder F3: Visual Builder UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new `strategy-builder.html` page where users build a custom strategy's entry/exit rules through draggable condition cards, test-run it against real data via the existing `/api/backtests` endpoint, and save it via F2's `/api/strategies` endpoints.

**Architecture:** A new page pair (`strategy-builder.html` + `src/strategy-builder.js`) following the codebase's existing one-page-per-file pattern. Two condition-list panels (Entry/Exit) backed by plain JS arrays (`entryConditions`/`exitConditions`), rendered into DOM cards on every mutation. Test Run and Save reuse existing backend contracts (`/api/backtests` with `strategy: "custom"`, `/api/strategies`) and the existing shared `results-renderer.js` — no new backend work.

**Tech Stack:** Vanilla JS (ES modules), native HTML5 Drag and Drop API — no new dependencies, matching every existing frontend page.

## Global Constraints

- No node-graph/canvas library — condition cards in two flat panels, not connected nodes (per spec's Canvas Approach section).
- Drag-to-reorder is cosmetic only (combinator application is order-independent) — included for UX polish, not correctness (per spec's Layout section).
- Test Run works without login (matches `/api/backtests`'s existing anonymous-allowed behavior); Save requires login (per spec's Test Run and Save & Load sections).
- This page is manual-browser-verified only — no JS test framework exists in this codebase to extend (per spec's Testing section). Every task ends with a manual verification step using the browser tools instead of an automated test run.

---

### Task 1: Page scaffold + condition card rendering (add/remove/edit)

**Files:**
- Create: `frontend/strategy-builder.html`
- Create: `frontend/src/strategy-builder.js`

**Interfaces:**
- Consumes: nothing new.
- Produces: module-level `entryConditions`/`exitConditions` arrays of `{left: {type, length?, std?, value?}, comparator, right: {...}}` objects; `renderPanel(panelName)` re-renders a panel's cards from its array; `currentRules()` returns `{entry: {...}, exit: {...}}` in F1's exact rule-JSON shape — Task 2 (drag), Task 3 (Test Run), and Task 4 (Save/Load) all call `currentRules()` and `renderPanel()`.

- [ ] **Step 1: Write the page scaffold**

```html
<!-- frontend/strategy-builder.html -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — Strategy Builder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <style>
    :root {
      --bg:#020617;--panel:rgba(15,23,42,.7);--border:rgba(148,163,184,.15);
      --text:#e5e7eb;--muted:#94a3b8;--accent:#22d3ee;
      --green:#22c55e;--red:#ef4444;--warn-bg:rgba(250,204,21,.1);--warn-border:rgba(250,204,21,.3)
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
    body{margin:0;min-height:100vh;color:var(--text);background:var(--bg)}
    .container{max-width:1100px;margin:0 auto;padding:48px 24px}
    h1{font-size:32px;margin-bottom:8px}
    .back-link{color:var(--accent);text-decoration:none;font-size:14px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px;margin-top:24px}
    label{display:block;font-size:13px;color:var(--muted);margin:14px 0 6px}
    input,select{padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:14px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    button{padding:10px 18px;background:var(--accent);color:var(--bg);border:none;
      border-radius:10px;font-weight:700;cursor:pointer;font-size:14px}
    button:disabled{opacity:.5;cursor:not-allowed}
    button.secondary{background:#0f172a;color:var(--text);border:1px solid var(--border)}
    #error{color:var(--red);margin-top:14px;display:none}
    #saveError{color:var(--red);margin-top:10px;display:none;font-size:14px}
    #results{margin-top:24px;display:none}
    .metrics-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:16px}
    .metric-box{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px}
    .metric-box label{margin:0 0 4px}
    .metric-box .value{font-size:20px;font-weight:700}
    .metric-box .value.negative{color:var(--red)}
    #equityCurve{width:100%;height:160px;margin-top:20px;background:#0f172a;border-radius:10px;border:1px solid var(--border)}
    .disclosure{margin-top:20px;padding:16px 18px;border-radius:10px;background:var(--warn-bg);
      border:1px solid var(--warn-border);color:#fde68a;font-size:14px;line-height:1.6}
    .disclosure strong{display:block;margin-bottom:6px;color:#fcd34d}

    .panels{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .panel{background:#0f172a;border:1px solid var(--border);border-radius:12px;padding:16px}
    .panel-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
    .panel-header h3{margin:0;font-size:16px}
    .panel-header select{padding:6px 10px;font-size:13px}
    .condition-card{display:flex;gap:8px;align-items:center;flex-wrap:wrap;
      border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px;
      background:var(--panel)}
    .condition-card[draggable="true"]{cursor:grab}
    .condition-card.dragging{opacity:.4}
    .condition-card select,.condition-card input{padding:6px 8px;font-size:13px}
    .condition-card input[type=number]{width:64px}
    .remove-btn{margin-left:auto;background:none;border:none;color:var(--red);
      cursor:pointer;font-size:16px;padding:0 4px}
    .add-condition-btn{width:100%;margin-top:4px}
  </style>
</head>
<body>
<div class="container">
  <a class="back-link" href="/">← Back</a>
  <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
  <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
  <a href="/backtest.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Backtest</a>
  <h1>Strategy Builder</h1>
  <p style="color:var(--muted)">Build a custom strategy from indicator conditions — no coding required.</p>

  <div class="card">
    <label for="loadSelect">Load from saved</label>
    <select id="loadSelect">
      <option value="">— start from scratch —</option>
    </select>

    <div class="panels" style="margin-top:20px">
      <div class="panel">
        <div class="panel-header">
          <h3>Entry</h3>
          <select id="entryCombinator">
            <option value="all">ALL (AND)</option>
            <option value="any">ANY (OR)</option>
          </select>
        </div>
        <div id="entryConditions"></div>
        <button type="button" class="secondary add-condition-btn" id="addEntryCondition">+ Add Condition</button>
      </div>
      <div class="panel">
        <div class="panel-header">
          <h3>Exit</h3>
          <select id="exitCombinator">
            <option value="all">ALL (AND)</option>
            <option value="any">ANY (OR)</option>
          </select>
        </div>
        <div id="exitConditions"></div>
        <button type="button" class="secondary add-condition-btn" id="addExitCondition">+ Add Condition</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Test Run</h3>
    <div class="row">
      <div>
        <label for="symbol">Symbol</label>
        <input id="symbol" placeholder="AAPL or BTC/USDT" value="AAPL" style="width:100%" />
      </div>
      <div>
        <label for="assetClass">Asset class</label>
        <select id="assetClass" style="width:100%">
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
          <option value="commodity">Commodity</option>
          <option value="forex">Forex</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div>
        <label for="startDate">Start date</label>
        <input id="startDate" type="date" value="2023-01-01" style="width:100%" />
      </div>
      <div>
        <label for="endDate">End date</label>
        <input id="endDate" type="date" value="2026-01-01" style="width:100%" />
      </div>
    </div>
    <button id="testRunButton" style="margin-top:16px">Test Run</button>
    <div id="error"></div>
  </div>

  <div id="results" class="card">
    <h2 id="resultTitle"></h2>
    <div class="metrics-grid">
      <div class="metric-box"><label>Total return</label><div class="value" id="mTotalReturn"></div></div>
      <div class="metric-box"><label>Win rate</label><div class="value" id="mWinRate"></div></div>
      <div class="metric-box"><label>Max drawdown</label><div class="value negative" id="mDrawdown"></div></div>
      <div class="metric-box"><label>Sharpe</label><div class="value" id="mSharpe"></div></div>
      <div class="metric-box"><label>Trades</label><div class="value" id="mTrades"></div></div>
      <div class="metric-box"><label>Losing trades</label><div class="value" id="mLosingTrades"></div></div>
    </div>
    <svg id="equityCurve"></svg>
    <div class="disclosure">
      <strong id="dConfidence"></strong>
      <div id="dAttribution"></div>
      <div id="dRisk" style="margin-top:8px"></div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Save Strategy</h3>
    <label for="strategyName">Name</label>
    <input id="strategyName" placeholder="My EMA Crossover" style="width:100%" />
    <label for="strategyDescription">Description (optional)</label>
    <input id="strategyDescription" placeholder="" style="width:100%" />
    <button id="saveButton" style="margin-top:16px">Save Strategy</button>
    <span id="loginHint" style="display:none;color:var(--muted);font-size:14px;margin-left:12px">Log in to save</span>
    <div id="saveError"></div>
  </div>
</div>
<script type="module" src="/src/strategy-builder.js"></script>
</body>
</html>
```

- [ ] **Step 2: Write the state model and rendering logic**

```javascript
// frontend/src/strategy-builder.js
import { getToken, clearToken, isLoggedIn } from './auth.js';

const OPERAND_TYPES = [
  { value: 'close', label: 'Close' },
  { value: 'open', label: 'Open' },
  { value: 'high', label: 'High' },
  { value: 'low', label: 'Low' },
  { value: 'volume', label: 'Volume' },
  { value: 'ema', label: 'EMA' },
  { value: 'sma', label: 'SMA' },
  { value: 'rsi', label: 'RSI' },
  { value: 'atr', label: 'ATR' },
  { value: 'bb_upper', label: 'Bollinger Upper' },
  { value: 'bb_mid', label: 'Bollinger Mid' },
  { value: 'bb_lower', label: 'Bollinger Lower' },
  { value: 'value', label: 'Value' },
];

const COMPARATORS = [
  { value: 'crosses_above', label: 'crosses above' },
  { value: 'crosses_below', label: 'crosses below' },
  { value: 'greater_than', label: 'greater than' },
  { value: 'less_than', label: 'less than' },
];

const LENGTH_ONLY_TYPES = ['ema', 'sma', 'rsi', 'atr'];
const BB_TYPES = ['bb_upper', 'bb_mid', 'bb_lower'];

function defaultCondition() {
  return {
    left: { type: 'ema', length: 20 },
    comparator: 'crosses_above',
    right: { type: 'ema', length: 50 },
  };
}

export let entryConditions = [defaultCondition()];
export let exitConditions = [defaultCondition()];

function operandToJSON(operand) {
  if (operand.type === 'value') {
    return { value: Number(operand.value) };
  }
  const json = { indicator: operand.type };
  if (LENGTH_ONLY_TYPES.includes(operand.type) || BB_TYPES.includes(operand.type)) {
    json.length = Number(operand.length);
  }
  if (BB_TYPES.includes(operand.type)) {
    json.std = Number(operand.std);
  }
  return json;
}

function ruleToJSON(conditions, combinatorEl) {
  return {
    combinator: combinatorEl.value,
    conditions: conditions.map((c) => ({
      left: operandToJSON(c.left),
      comparator: c.comparator,
      right: operandToJSON(c.right),
    })),
  };
}

export function currentRules() {
  return {
    entry: ruleToJSON(entryConditions, document.getElementById('entryCombinator')),
    exit: ruleToJSON(exitConditions, document.getElementById('exitCombinator')),
  };
}

function renderOperandFields(operand, onTypeChange) {
  const wrap = document.createElement('span');
  wrap.style.display = 'inline-flex';
  wrap.style.gap = '6px';

  const typeSelect = document.createElement('select');
  OPERAND_TYPES.forEach((t) => {
    const opt = document.createElement('option');
    opt.value = t.value;
    opt.textContent = t.label;
    if (t.value === operand.type) opt.selected = true;
    typeSelect.appendChild(opt);
  });
  typeSelect.addEventListener('change', () => {
    operand.type = typeSelect.value;
    if (operand.type === 'value') {
      operand.value = operand.value ?? 0;
    } else if (LENGTH_ONLY_TYPES.includes(operand.type) || BB_TYPES.includes(operand.type)) {
      operand.length = operand.length ?? 20;
      if (BB_TYPES.includes(operand.type)) operand.std = operand.std ?? 2.0;
    }
    onTypeChange();
  });
  wrap.appendChild(typeSelect);

  if (operand.type === 'value') {
    const valueInput = document.createElement('input');
    valueInput.type = 'number';
    valueInput.step = 'any';
    valueInput.value = operand.value ?? 0;
    valueInput.addEventListener('input', () => { operand.value = valueInput.value; });
    wrap.appendChild(valueInput);
  } else if (LENGTH_ONLY_TYPES.includes(operand.type) || BB_TYPES.includes(operand.type)) {
    const lengthInput = document.createElement('input');
    lengthInput.type = 'number';
    lengthInput.placeholder = 'length';
    lengthInput.value = operand.length ?? 20;
    lengthInput.addEventListener('input', () => { operand.length = lengthInput.value; });
    wrap.appendChild(lengthInput);

    if (BB_TYPES.includes(operand.type)) {
      const stdInput = document.createElement('input');
      stdInput.type = 'number';
      stdInput.step = '0.1';
      stdInput.placeholder = 'std';
      stdInput.value = operand.std ?? 2.0;
      stdInput.addEventListener('input', () => { operand.std = stdInput.value; });
      wrap.appendChild(stdInput);
    }
  }

  return wrap;
}

function renderConditionCard(condition, index, conditions, containerEl, panelName) {
  const card = document.createElement('div');
  card.className = 'condition-card';
  card.dataset.index = index;

  const rerender = () => renderPanel(panelName);

  card.appendChild(renderOperandFields(condition.left, rerender));

  const comparatorSelect = document.createElement('select');
  COMPARATORS.forEach((c) => {
    const opt = document.createElement('option');
    opt.value = c.value;
    opt.textContent = c.label;
    if (c.value === condition.comparator) opt.selected = true;
    comparatorSelect.appendChild(opt);
  });
  comparatorSelect.addEventListener('change', () => { condition.comparator = comparatorSelect.value; });
  card.appendChild(comparatorSelect);

  card.appendChild(renderOperandFields(condition.right, rerender));

  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.className = 'remove-btn';
  removeBtn.textContent = '✕';
  removeBtn.addEventListener('click', () => {
    conditions.splice(index, 1);
    renderPanel(panelName);
  });
  card.appendChild(removeBtn);

  containerEl.appendChild(card);
}

export function renderPanel(panelName) {
  const conditions = panelName === 'entry' ? entryConditions : exitConditions;
  const containerEl = document.getElementById(`${panelName}Conditions`);
  containerEl.innerHTML = '';
  conditions.forEach((condition, index) => {
    renderConditionCard(condition, index, conditions, containerEl, panelName);
  });
}

document.getElementById('addEntryCondition').addEventListener('click', () => {
  entryConditions.push(defaultCondition());
  renderPanel('entry');
});
document.getElementById('addExitCondition').addEventListener('click', () => {
  exitConditions.push(defaultCondition());
  renderPanel('exit');
});

const authStateEl = document.getElementById('authState');
if (authStateEl) {
  if (isLoggedIn()) {
    authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
    document.getElementById('logoutLink').addEventListener('click', (e) => {
      e.preventDefault();
      clearToken();
      window.location.reload();
    });
  } else {
    authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  }
}

renderPanel('entry');
renderPanel('exit');
```

- [ ] **Step 3: Manual verification**

1. Start the frontend dev server (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/strategy-builder.html`. Confirm the Entry and Exit panels each show one default condition card (EMA(20) crosses above/... EMA(50)) and no console errors.
3. Click "+ Add Condition" on the Entry panel; confirm a second card appears.
4. Change the second card's left operand type dropdown to "RSI"; confirm a "length" number input appears next to it (replacing whatever was there before).
5. Change a right operand type to "Value"; confirm it shows a single plain number input, not a length field.
6. Click a card's "✕"; confirm it's removed and the remaining card's index-based behavior still works (add another condition afterward, confirm no errors).
7. Open the browser console; confirm no errors throughout.

- [ ] **Step 4: Commit**

```bash
git add frontend/strategy-builder.html frontend/src/strategy-builder.js
git commit -m "feat(strategy-builder): add builder page scaffold with condition card add/remove/edit"
```

---

### Task 2: Drag-to-reorder condition cards

**Files:**
- Modify: `frontend/strategy-builder.html`
- Modify: `frontend/src/strategy-builder.js`

**Interfaces:**
- Consumes: `entryConditions`/`exitConditions`, `renderPanel(panelName)` from Task 1.
- Produces: nothing new for later tasks — this task only adds reordering to the existing cards.

- [ ] **Step 1: Add drag state and handlers**

In `frontend/src/strategy-builder.js`, add near the top (after the `BB_TYPES` constant):

```javascript
let draggedIndex = null;
let draggedPanel = null;
```

In `renderConditionCard`, change:

```javascript
function renderConditionCard(condition, index, conditions, containerEl, panelName) {
  const card = document.createElement('div');
  card.className = 'condition-card';
  card.dataset.index = index;
```

to:

```javascript
function renderConditionCard(condition, index, conditions, containerEl, panelName) {
  const card = document.createElement('div');
  card.className = 'condition-card';
  card.dataset.index = index;
  card.draggable = true;

  card.addEventListener('dragstart', () => {
    draggedIndex = index;
    draggedPanel = panelName;
    card.classList.add('dragging');
  });
  card.addEventListener('dragend', () => card.classList.remove('dragging'));
  card.addEventListener('dragover', (e) => e.preventDefault());
  card.addEventListener('drop', () => {
    if (draggedPanel !== panelName || draggedIndex === null) return;
    const [moved] = conditions.splice(draggedIndex, 1);
    conditions.splice(index, 0, moved);
    draggedIndex = null;
    renderPanel(panelName);
  });
```

(Leave the rest of `renderConditionCard`'s body — the operand fields, comparator select, remove button — exactly as Task 1 left it.)

- [ ] **Step 2: Manual verification**

1. Reload `http://localhost:3000/strategy-builder.html`.
2. Add 3 conditions to the Entry panel with visibly different settings (e.g. different comparators) so they're distinguishable.
3. Drag the 3rd card to the top position; confirm it visually moves and the array order changes (verify by checking that removing what's now the "first" card removes the one you dragged, not the original first one).
4. Confirm dragging a card from the Entry panel and dropping it doesn't affect the Exit panel's cards (the `draggedPanel !== panelName` guard).
5. Confirm no console errors during drag operations.

- [ ] **Step 3: Commit**

```bash
git add frontend/strategy-builder.html frontend/src/strategy-builder.js
git commit -m "feat(strategy-builder): add drag-to-reorder for condition cards"
```

---

### Task 3: Test Run wiring

**Files:**
- Modify: `frontend/strategy-builder.html` (already has the Test Run inputs and `#results` markup from Task 1 — no changes needed here, listed for completeness)
- Modify: `frontend/src/strategy-builder.js`

**Interfaces:**
- Consumes: `currentRules()` from Task 1; `renderBacktestResult(result)` from `frontend/src/results-renderer.js` (existing, shared with `backtest.html`/`history.html`); `getToken()` from `frontend/src/auth.js`.
- Produces: nothing new for later tasks — this task wires the existing Test Run button.

- [ ] **Step 1: Add the Test Run handler**

In `frontend/src/strategy-builder.js`, add the import:

```javascript
import { renderBacktestResult } from './results-renderer.js';
```

alongside the existing `import { getToken, clearToken, isLoggedIn } from './auth.js';` line, then append this at the end of the file:

```javascript
const API_BASE = 'http://localhost:8000/api';

document.getElementById('testRunButton').addEventListener('click', async () => {
  const button = document.getElementById('testRunButton');
  const errorEl = document.getElementById('error');
  const resultsEl = document.getElementById('results');

  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  button.disabled = true;
  button.textContent = 'Running…';

  const payload = {
    symbol: document.getElementById('symbol').value.trim(),
    asset_class: document.getElementById('assetClass').value,
    strategy: 'custom',
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: currentRules(),
  };

  try {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    const token = getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE}/backtests`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Test run failed');
    }

    renderBacktestResult(body.result);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
    button.textContent = 'Test Run';
  }
});
```

- [ ] **Step 2: Manual verification**

1. Start all three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend`, port 8000), frontend (`chartsense`, port 3000).
2. Open `http://localhost:3000/strategy-builder.html` (logged out).
3. Leave the default EMA(20)/EMA(50) crossover conditions in both panels, set Symbol to `AAPL`, Asset class `Equity`, a real date range (e.g. `2022-01-01` to `2024-01-01`).
4. Click "Test Run". Confirm it shows "Running…", then renders real metrics (total return, win rate, trade count, etc.) via the shared results panel, with no console errors — this proves the rule JSON built by the UI round-trips correctly through `/api/backtests` → the analytics service's `custom` strategy (built in F1) → real backtest metrics.
5. Add a nonsensical condition (e.g. `Close greater than Value 999999999`) to the Entry panel with `ALL` combinator, so entry never fires; Test Run again; confirm it still succeeds with `trade_count: 0` rather than erroring (an empty-but-valid backtest).
6. Stop all three dev servers.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/strategy-builder.js
git commit -m "feat(strategy-builder): wire Test Run to /api/backtests with the built rule JSON"
```

---

### Task 4: Save Strategy + Load from saved

**Files:**
- Modify: `frontend/src/strategy-builder.js`

**Interfaces:**
- Consumes: `currentRules()`, `entryConditions`/`exitConditions`, `renderPanel(panelName)` from Task 1; `getToken()`/`isLoggedIn()` from `auth.js`.
- Produces: nothing new for later tasks — final builder-logic task in the plan.

- [ ] **Step 1: Add auth-gating for the Save UI, the Save handler, and the Load-from-saved logic**

Append to `frontend/src/strategy-builder.js`:

```javascript
const saveButton = document.getElementById('saveButton');
const loginHint = document.getElementById('loginHint');
const loadSelect = document.getElementById('loadSelect');

if (!isLoggedIn()) {
  saveButton.disabled = true;
  loginHint.style.display = 'inline';
}

async function loadSavedStrategiesList() {
  if (!isLoggedIn()) return;

  const response = await fetch(`${API_BASE}/strategies`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) return;
  const body = await response.json();

  (body.data || []).forEach((strategy) => {
    const opt = document.createElement('option');
    opt.value = strategy.id;
    opt.textContent = strategy.name;
    loadSelect.appendChild(opt);
  });
}

function operandFromJSON(operand) {
  if ('value' in operand) return { type: 'value', value: operand.value };
  const result = { type: operand.indicator };
  if ('length' in operand) result.length = operand.length;
  if ('std' in operand) result.std = operand.std;
  return result;
}

function conditionsFromRule(rule) {
  return rule.conditions.map((c) => ({
    left: operandFromJSON(c.left),
    comparator: c.comparator,
    right: operandFromJSON(c.right),
  }));
}

loadSelect.addEventListener('change', async () => {
  if (!loadSelect.value) return;

  const response = await fetch(`${API_BASE}/strategies/${loadSelect.value}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) return;
  const strategy = await response.json();

  entryConditions.length = 0;
  entryConditions.push(...conditionsFromRule(strategy.rules.entry));
  exitConditions.length = 0;
  exitConditions.push(...conditionsFromRule(strategy.rules.exit));
  document.getElementById('entryCombinator').value = strategy.rules.entry.combinator;
  document.getElementById('exitCombinator').value = strategy.rules.exit.combinator;
  document.getElementById('strategyName').value = strategy.name;
  document.getElementById('strategyDescription').value = strategy.description || '';

  renderPanel('entry');
  renderPanel('exit');
});

saveButton.addEventListener('click', async () => {
  const saveErrorEl = document.getElementById('saveError');
  saveErrorEl.style.display = 'none';

  if (entryConditions.length === 0 || exitConditions.length === 0) {
    saveErrorEl.textContent = 'Both Entry and Exit need at least one condition.';
    saveErrorEl.style.display = 'block';
    return;
  }

  const name = document.getElementById('strategyName').value.trim();
  if (!name) {
    saveErrorEl.textContent = 'Name is required.';
    saveErrorEl.style.display = 'block';
    return;
  }

  saveButton.disabled = true;
  saveButton.textContent = 'Saving…';

  try {
    const response = await fetch(`${API_BASE}/strategies`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({
        name,
        description: document.getElementById('strategyDescription').value.trim() || null,
        rules: currentRules(),
      }),
    });
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.error || 'Save failed');
    }

    saveErrorEl.style.display = 'none';
    alert(`Saved "${body.name}"`);
  } catch (err) {
    saveErrorEl.textContent = err.message;
    saveErrorEl.style.display = 'block';
  } finally {
    saveButton.disabled = false;
    saveButton.textContent = 'Save Strategy';
  }
});

loadSavedStrategiesList();
```

- [ ] **Step 2: Manual verification**

1. Start the analytics, backend, and frontend dev servers.
2. Register/log in via `http://localhost:3000/register.html` (or `login.html` with an existing account) so `strategy-builder.html`'s `authState` shows "Log out".
3. Open `http://localhost:3000/strategy-builder.html`. Confirm the Save button is enabled and the "Log in to save" hint is hidden.
4. Build a real rule (default EMA crossover is fine), type a name (e.g. "My Test Strategy"), click "Save Strategy". Confirm the success alert and no console errors.
5. Reload the page. Confirm "My Test Strategy" now appears in the "Load from saved" dropdown.
6. Select it. Confirm both panels repopulate with the correct conditions (same operand types, lengths, comparators, combinators) and the Name/Description fields repopulate too.
7. Log out (via the header link), reload the page. Confirm the Save button is disabled and "Log in to save" is visible, and the Load dropdown has no saved strategies (since `isLoggedIn()` gates the fetch).
8. Stop all three dev servers.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/strategy-builder.js
git commit -m "feat(strategy-builder): wire Save Strategy and Load from saved to /api/strategies"
```

---

### Task 5: Navigation links + full end-to-end verification

**Files:**
- Modify: `frontend/index.html`
- Modify: `frontend/backtest.html`
- Modify: `frontend/history.html`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new — final task in the plan.

- [ ] **Step 1: Add a "Strategy Builder" nav link to `index.html`**

In `frontend/index.html`, find the `<nav>` block:

```html
  <nav style="margin-top:14px">
    <a href="/backtest.html" style="color:var(--accent);text-decoration:none;font-size:15px">
      → Run a real backtest
    </a>
    <a href="/login.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
      Log in
    </a>
  </nav>
```

and add a Strategy Builder link between the two existing ones:

```html
  <nav style="margin-top:14px">
    <a href="/backtest.html" style="color:var(--accent);text-decoration:none;font-size:15px">
      → Run a real backtest
    </a>
    <a href="/strategy-builder.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
      Strategy Builder
    </a>
    <a href="/login.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
      Log in
    </a>
  </nav>
```

- [ ] **Step 2: Add a "Strategy Builder" nav link to `backtest.html`**

In `frontend/backtest.html`, find:

```html
  <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
```

and add immediately after it:

```html
  <a href="/history.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">History</a>
  <a href="/strategy-builder.html" style="float:right;font-size:14px;color:var(--accent);text-decoration:none;margin-right:16px">Strategy Builder</a>
```

- [ ] **Step 3: Add a "Strategy Builder" nav link to `history.html`**

Read `frontend/history.html` first to find its equivalent header nav-link markup (it follows the same `float:right` pattern as `backtest.html`'s `History`/`Log out` links), then add a `Strategy Builder` link there using the identical style attributes already used by its neighboring links.

- [ ] **Step 4: Full end-to-end manual verification**

1. Start all three dev servers.
2. From `http://localhost:3000/index.html`, click "Strategy Builder"; confirm it navigates to `strategy-builder.html`.
3. From `backtest.html` and `history.html`, confirm the "Strategy Builder" link is present and navigates correctly.
4. On `strategy-builder.html`, build a genuinely different strategy from Task 4's (e.g. an RSI-threshold entry, EMA-crossover exit), Test Run it against `BTC/USDT`/crypto with a real date range, confirm real results render.
5. Save it, confirm it's retrievable via a direct `curl -H "Authorization: Bearer <token>" http://localhost:8000/api/strategies` check matching what was built in the UI.
6. Stop all three dev servers.

- [ ] **Step 5: Commit**

```bash
git add frontend/index.html frontend/backtest.html frontend/history.html
git commit -m "feat(strategy-builder): add Strategy Builder nav links across the frontend"
```
