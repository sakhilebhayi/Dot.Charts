import { getToken, clearToken, isLoggedIn, logout } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';
import { showFailure } from './ecosystem.js';

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

let draggedIndex = null;
let draggedPanel = null;

function defaultCondition() {
  return {
    left: { type: 'ema', length: 20 },
    comparator: 'crosses_above',
    right: { type: 'ema', length: 50 },
  };
}

export let entryConditions = [defaultCondition()];
// The exit starts as the mirror of the entry, not a copy of it: an exit
// identical to the entry can never fire after a position opens, so the
// out-of-the-box Test Run would silently produce zero trades.
export let exitConditions = [{ ...defaultCondition(), comparator: 'crosses_below' }];

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
    document.getElementById('logoutLink').addEventListener('click', async (e) => {
      e.preventDefault();
      await logout();
      window.location.reload();
    });
  } else {
    authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  }
}

renderPanel('entry');
renderPanel('exit');

import { API_BASE } from './api-base.js';

document.getElementById('testRunButton').addEventListener('click', async () => {
  let failStatus = null;
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
    failStatus = response.status;
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Test run failed');
    }

    renderBacktestResult(body.result);
  } catch (err) {
    showFailure(errorEl, err.message, failStatus);
  } finally {
    button.disabled = false;
    button.textContent = 'Test Run';
  }
});

const saveButton = document.getElementById('saveButton');
const loginHint = document.getElementById('loginHint');
const loadSelect = document.getElementById('loadSelect');

if (!isLoggedIn()) {
  saveButton.disabled = true;
  loginHint.style.display = 'inline';
}

async function loadSavedStrategiesList() {
  if (!isLoggedIn()) return;

  const errorEl = document.getElementById('error');

  try {
    let url = `${API_BASE}/strategies`;
    const strategies = [];

    while (url) {
      const response = await fetch(url, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
      });

      if (response.status === 401) {
        // Stale token: clear it and reflect the logged-out state instead of
        // silently leaving the header showing "Log out" with no explanation.
        clearToken();
        const authStateEl = document.getElementById('authState');
        if (authStateEl) {
          authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
        }
        saveButton.disabled = true;
        loginHint.style.display = 'inline';
        if (errorEl) {
          errorEl.textContent = 'Your session has expired. Log in again to see your saved strategies.';
          errorEl.style.display = 'block';
        }
        return;
      }

      if (!response.ok) return;
      const body = await response.json();
      strategies.push(...(body.data || []));
      url = body.next_page_url || null;
    }

    strategies.forEach((strategy) => {
      const opt = document.createElement('option');
      opt.value = strategy.id;
      opt.textContent = strategy.name;
      loadSelect.appendChild(opt);
    });
  } catch (err) {
    if (errorEl) {
      errorEl.textContent = 'Could not load your saved strategies.';
      errorEl.style.display = 'block';
    }
  }
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
  saveErrorEl.style.color = ''; // back to error styling after a green "Saved ✓"

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

    // Inline confirmation instead of a blocking alert(), and the saved
    // strategy joins the "Load from saved" dropdown immediately.
    saveErrorEl.textContent = `Saved "${body.name}" ✓`;
    saveErrorEl.style.color = 'var(--success)';
    saveErrorEl.style.display = 'block';
    const opt = document.createElement('option');
    opt.value = body.id;
    opt.textContent = body.name;
    loadSelect.appendChild(opt);
    loadSelect.value = body.id;
  } catch (err) {
    saveErrorEl.textContent = err.message;
    saveErrorEl.style.display = 'block';
  } finally {
    saveButton.disabled = false;
    saveButton.textContent = 'Save Strategy';
  }
});

loadSavedStrategiesList();
