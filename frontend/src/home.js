// The signed-in Home (home.html): the account's own backtests as a master-detail dashboard. The page is complete
// without this script (headline, primary action, empty states ship in the markup); this fills in what the API knows
// about the person. Every figure is a field of a stored record: nothing is computed across runs and nothing is
// invented. The equity curve is NOT in the list response; it is fetched for the one selected run.
import { refreshHeader } from './app-header.js';
import { STRATEGY_NAMES } from './labels.js';
import { getToken, clearToken, isLoggedIn } from './auth.js';
import { API_BASE } from './api-base.js';
import { drawEquityChart, money, day } from './equity-chart.js';

const SHOWN = 3;

const ASSET_NAMES = { equity: 'Equity', crypto: 'Crypto', commodity: 'Commodity', forex: 'Forex' };
const BAND = { high: 'High, 30 or more trades', medium: 'Medium, 10 to 29 trades', low: 'Low, fewer than 10 trades' };
const NS = 'http://www.w3.org/2000/svg';
const motion = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const $ = (id) => document.getElementById(id);
const headline = $('headline');
const headlineSub = $('headlineSub');
const primary = document.querySelector('[data-primary-action]');
const headAct = document.querySelector('.head-act');
const desk = $('desk');
const stage = $('stage');
const rowsEl = $('rows');

function el(tag, className, content) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (content !== undefined) node.textContent = content; // records are user text: never innerHTML
  return node;
}
const link = (label, href, className) => Object.assign(el('a', className, label), { href });
const mon = (v) => {
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
};
const sgn = (n, d = 2) => `${n > 0 ? '+' : ''}${n.toFixed(d)}`;
const instrument = (r) => `${r.symbol}${r.strategy === 'pairs_trading' && r.params?.symbol_b ? ` vs. ${r.params.symbol_b}` : ''}`;
const metrics = (r) => (r && r.results && r.results.metrics) || null;

// A figure that counts up to its true value. The visible text animates; screen readers get the final text only.
function figure(value, format, className) {
  const final = format(value);
  const node = el('span', className || 'num');
  const shown = el('span', null, final);
  shown.setAttribute('aria-hidden', 'true');
  node.append(shown, el('span', 'sr', final));
  if (motion() && Number.isFinite(value)) {
    const began = performance.now();
    const duration = 380;
    const tick = (now) => {
      const t = Math.min(1, (now - began) / duration);
      shown.textContent = t < 1 ? format(value * (1 - (1 - t) ** 3)) : final;
      if (t < 1) requestAnimationFrame(tick);
    };
    shown.textContent = format(0);
    requestAnimationFrame(tick);
  }
  return node;
}

/* ---------- account chrome ---------- */
function setSignedOut(message) {
  refreshHeader(); // the bar stops offering the account
  headline.textContent = message;
  headlineSub.textContent = 'Log in to see the runs, strategies and notes kept on your account. The chart read works without an account.';
  primary.textContent = 'Log in';
  primary.href = '/login.html';
  if (!headAct.querySelector('.second')) headAct.append(link('Create account', '/register.html', 'btn second'));
  desk.hidden = true;
  $('firstRun').hidden = true;
  $('pair').hidden = true;
  $('foot').removeAttribute('data-pending');
  $('foot').hidden = true;
  $('instruments').hidden = false;
}

async function get(path) {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (response.status === 401) throw Object.assign(new Error('unauthenticated'), { status: 401 });
  if (!response.ok) throw Object.assign(new Error('unavailable'), { status: response.status });
  return response.json();
}

/* ---------- state ---------- */
const state = {
  account: { total: 0, latest: null }, // unfiltered: the headline and the foot
  runs: [],
  total: 0, // for the current filters
  sel: null,
  order: 'created_at',
  dir: 'descending',
  strategy: '',
  status: '',
  curves: new Map(),
  token: 0,
  first: true,
};

function setHeadline(total, latest) {
  headline.textContent = `${total} backtest${total === 1 ? '' : 's'} kept on this account.`;
  headlineSub.replaceChildren();
  if (!latest) return;
  if (latest.status === 'failed') {
    headlineSub.append(`The latest run, ${instrument(latest)}, failed: ${latest.error || 'no reason was recorded'}.`);
    return;
  }
  const m = metrics(latest);
  headlineSub.append(`Latest: ${instrument(latest)}, ${STRATEGY_NAMES[latest.strategy] || latest.strategy}, run on ${day(latest.created_at)}${m ? ', total return ' : '.'}`);
  if (m) headlineSub.append(figure(m.total_return_pct, (v) => `${sgn(v)}%`, `num${m.total_return_pct < 0 ? ' loss' : ''}`), '.');
}

function renderFoot(counts) {
  const foot = $('foot');
  foot.replaceChildren(...counts.filter((c) => c[1] != null).map(([label, n]) => {
    const s = el('span', null, label);
    s.append(el('b', null, String(n)));
    return s;
  }));
  foot.removeAttribute('data-pending');
  foot.hidden = false;
}

/* ---------- the runs table ---------- */
const valueOf = (r, k) => {
  if (k === 'created_at') return r.created_at;
  const m = metrics(r);
  if (!m) return null;
  return { ret: m.total_return_pct, win: m.win_rate_pct, dd: m.max_drawdown_pct, n: m.trade_count }[k];
};
function sorted() {
  return state.runs.slice().sort((a, b) => {
    const p = valueOf(a, state.order);
    const q = valueOf(b, state.order);
    if (p == null && q == null) return 0;
    if (p == null) return 1;
    if (q == null) return -1;
    const c = p > q ? 1 : p < q ? -1 : 0;
    return state.dir === 'ascending' ? c : -c;
  });
}
const LABELS = { ret: 'Return', win: 'Win rate', dd: 'Max DD', n: 'Trades' }; // the stacked cards on a phone name each figure
function figureCell(r, k, format, cls) {
  const v = valueOf(r, k);
  const td = el('td', `num ${cls || ''}`.trim());
  td.dataset.label = LABELS[k];
  if (v == null) td.append(el('span', 'dash', '—'));
  else {
    td.textContent = format(v);
    if ((k === 'ret' || k === 'dd') && v < 0) td.classList.add('neg');
  }
  return td;
}
function drawRows() {
  rowsEl.replaceChildren();
  const list = sorted();
  if (!list.length) {
    const td = Object.assign(el('td', 'rows-note', 'No run matches these filters.'), { colSpan: 5 });
    const tr = el('tr');
    tr.append(td);
    rowsEl.append(tr);
  }
  list.forEach((r, i) => {
    const tr = el('tr');
    tr.id = `r${r.id}`;
    tr.setAttribute('aria-selected', String(r.id === state.sel));
    tr.style.setProperty('--i', String(Math.min(i, 9)));
    const cell = el('td');
    const wrap = el('span', 'inst');
    const a = Object.assign(el('a'), { href: `?run=${r.id}` });
    a.dataset.id = String(r.id);
    const sym = el('span', 'sym', instrument(r));
    if (r.status === 'failed') sym.append(el('span', 'st failed', 'Failed'));
    if (r.status === 'queued') sym.append(el('span', 'st', 'Queued'));
    const period = `${mon(r.start_date)} to ${mon(r.end_date)}`;
    a.append(sym, el('small', null, [STRATEGY_NAMES[r.strategy] || r.strategy, period].join(', ')));
    wrap.append(a);
    cell.append(wrap);
    tr.append(
      cell,
      figureCell(r, 'ret', (v) => `${sgn(v)}%`),
      figureCell(r, 'win', (v) => `${v.toFixed(1)}%`, 'c-win'),
      figureCell(r, 'dd', (v) => `${v.toFixed(2)}%`, 'c-dd'),
      figureCell(r, 'n', String, 'c-n'),
    );
    rowsEl.append(tr);
  });
  $('more').replaceChildren(
    el('span', null, `Showing ${list.length} of ${state.total} runs`),
    link('Open history', '/history.html'),
  );
}

/* ---------- the stage: the selected run ---------- */
function fact(dl, k, v, cls) {
  const row = el('div');
  const dd = el('dd', cls || null);
  if (v instanceof Node) dd.append(v); else dd.textContent = v;
  row.append(el('dt', null, k), dd);
  dl.append(row);
}
function plate() {
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('class', 'plate');
  svg.setAttribute('viewBox', '0 0 400 160');
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  [20, 60, 100, 140].forEach((y) => {
    const l = document.createElementNS(NS, 'line');
    [['x1', 0], ['x2', 400], ['y1', y], ['y2', y], ['class', 'g'], ['vector-effect', 'non-scaling-stroke']].forEach(([k, v]) => l.setAttribute(k, v));
    svg.append(l);
  });
  const b = document.createElementNS(NS, 'line');
  [['x1', 0], ['x2', 400], ['y1', 80], ['y2', 80], ['class', 'b'], ['vector-effect', 'non-scaling-stroke']].forEach(([k, v]) => b.setAttribute(k, v));
  svg.append(b);
  return svg;
}
function rerun(run) {
  sessionStorage.setItem('chartsense_rerun', JSON.stringify({
    symbol: run.symbol, asset_class: run.asset_class, strategy: run.strategy, params: run.params,
    start_date: run.start_date, end_date: run.end_date,
  }));
  window.location.href = '/backtest.html';
}

function paintChart(run, host, animate) {
  const curve = state.curves.get(run.id);
  if (!curve) return;
  drawEquityChart(host, curve, instrument(run), { animate: animate && motion() });
}

async function loadCurve(run, host, foot, tview) {
  const mine = (state.token += 1);
  if (state.curves.has(run.id)) {
    paintChart(run, host, true);
    return;
  }
  host.replaceChildren(el('p', 'chart-wait', 'Loading the curve.'));
  try {
    const full = await get(`/backtests/${run.id}`);
    const points = full.results && full.results.equity_curve;
    if (mine !== state.token) return; // another run was picked meanwhile
    if (!Array.isArray(points) || points.length < 2) {
      host.replaceChildren(plate(), el('p', 'note', 'This run recorded no equity curve.'));
      host.className = '';
      foot.hidden = true;
      return;
    }
    state.curves.set(run.id, points);
    paintChart(run, host, true);
    const body = el('tbody');
    points.forEach((p) => { const tr = el('tr'); tr.append(el('td', null, p.time), el('td', null, money(p.equity))); body.append(tr); });
    const table = el('table');
    const head = el('thead');
    const hr = el('tr');
    ['Date', 'Equity'].forEach((h) => hr.append(Object.assign(el('th', null, h), { scope: 'col' })));
    head.append(hr);
    table.append(head, body);
    tview.replaceChildren(table);
    foot.querySelector('.span').textContent = `Equity, ${day(points[0].time)} to ${day(points[points.length - 1].time)}`;
  } catch (error) {
    if (mine !== state.token) return;
    if (error.status === 401) { clearToken(); setSignedOut('Your session has expired.'); return; }
    host.replaceChildren(el('p', 'chart-wait', 'The curve could not be loaded just now. Pick the run again to retry.'));
  }
}

function confirmDelete(run, acts) {
  if (stage.querySelector('.confirm')) return;
  const box = el('div', 'confirm');
  box.setAttribute('role', 'alertdialog');
  box.setAttribute('aria-label', 'Delete this run');
  const message = el('p', null, 'Delete this backtest run? This cannot be undone.');
  const keep = el('button', 'btn', 'Keep it');
  keep.type = 'button';
  const go = el('button', 'btn warn', 'Delete run');
  go.type = 'button';
  const row = el('div', 'acts');
  row.append(keep, go);
  box.append(message, row);
  acts.closest('.stage-head').after(box);
  keep.focus();
  keep.addEventListener('click', () => { box.remove(); acts.querySelector('.warn').focus(); });
  go.addEventListener('click', async () => {
    go.disabled = true;
    try {
      const response = await fetch(`${API_BASE}/backtests/${run.id}`, {
        method: 'DELETE', headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
      });
      if (response.status === 401) { clearToken(); setSignedOut('Your session has expired.'); return; }
      if (!response.ok) throw new Error('failed');
      state.curves.delete(run.id);
      state.sel = null;
      await loadAll({ keepSelection: false });
    } catch {
      go.disabled = false;
      message.textContent = 'This run could not be deleted just now. Nothing was changed.';
    }
  });
}

function drawStage() {
  stage.replaceChildren();
  stage.classList.remove('settle');
  state.token += 1;
  const run = state.runs.find((r) => r.id === state.sel);
  if (!run) {
    stage.append(el('p', 'note', 'Select a run to see it here.'));
    return;
  }
  stage.classList.add('settle');
  const head = el('div', 'stage-head');
  const titles = el('div');
  titles.append(
    el('h2', null, instrument(run)),
    el('p', 'sub', `${[STRATEGY_NAMES[run.strategy] || run.strategy, ASSET_NAMES[run.asset_class] || run.asset_class].join(', ')}, run on ${day(run.created_at)}`),
  );
  const acts = el('div', 'acts');
  const again = el('button', 'btn', 'Re-run');
  again.type = 'button';
  again.addEventListener('click', () => rerun(run));
  const params = new URLSearchParams({ backtest_run_id: run.id, symbol: run.symbol });
  const del = el('button', 'btn quiet warn', 'Delete');
  del.type = 'button';
  del.addEventListener('click', () => confirmDelete(run, acts));
  acts.append(again, link('Add to journal', `/journal.html?${params.toString()}`, 'btn quiet'), del);
  head.append(titles, acts);
  stage.append(head);

  const m = metrics(run);
  if (run.status !== 'complete' || !m) {
    const dl = el('dl', 'rows');
    fact(dl, 'Status', run.status === 'failed' ? 'Failed' : 'Queued');
    fact(dl, 'Period', `${day(run.start_date)} to ${day(run.end_date)}`, 'num');
    stage.append(plate(), el('p', 'note', `This run did not produce results.${run.error ? ` Reason recorded: ${run.error}.` : ''}`), dl);
    return;
  }

  const host = el('div', 'chart');
  const foot = el('div', 'chart-foot');
  const caption = el('span', 'span', 'Equity');
  const toggle = el('button', null, 'View as table');
  toggle.type = 'button';
  toggle.setAttribute('aria-expanded', 'false');
  foot.append(caption, toggle);
  const tview = el('div', 'tview');
  tview.hidden = true;
  toggle.addEventListener('click', () => {
    tview.hidden = !tview.hidden;
    toggle.setAttribute('aria-expanded', String(!tview.hidden));
    toggle.textContent = tview.hidden ? 'View as table' : 'Hide table';
  });
  const dl = el('dl', 'rows');
  fact(dl, 'Total return', figure(m.total_return_pct, (v) => `${sgn(v)}%`), `num${m.total_return_pct < 0 ? ' neg' : ''}`);
  fact(dl, 'Win rate', `${m.win_rate_pct.toFixed(1)}%`, 'num');
  fact(dl, 'Max drawdown', `${m.max_drawdown_pct.toFixed(2)}%`, `num${m.max_drawdown_pct < 0 ? ' neg' : ''}`);
  fact(dl, 'Sharpe ratio', m.sharpe_ratio == null ? '—' : m.sharpe_ratio.toFixed(2), 'num');
  fact(dl, 'Trades', `${m.trade_count} (${m.losing_trade_count} losing)`, 'num');
  const band = run.results.disclosure && run.results.disclosure.confidence_band;
  if (band) fact(dl, 'Confidence', BAND[band] || band);
  stage.append(host, foot, tview, dl, el('p', 'note', 'A backtest describes the past and does not predict future results.'));

  if (run.has_equity_curve === false) {
    host.replaceChildren(plate(), el('p', 'note', 'This run recorded no equity curve.'));
    host.className = '';
    foot.hidden = true;
    return;
  }
  loadCurve(run, host, foot, tview);
}

function select(id, { focus = false } = {}) {
  if (!state.runs.some((r) => r.id === id)) return; // only ids that are in the loaded rows
  state.sel = id;
  rowsEl.querySelectorAll('tr[id]').forEach((tr) => tr.setAttribute('aria-selected', String(tr.id === `r${id}`)));
  const url = new URL(window.location.href);
  url.searchParams.set('run', String(id));
  window.history.replaceState(null, '', url);
  drawStage();
  if (focus) rowsEl.querySelector(`#r${id} a`)?.focus();
}

/* ---------- the lower lists ---------- */
function fillList(name, result, row) {
  const list = $(`${name}List`);
  const empty = $(`${name}Empty`);
  if (result.status === 'rejected') {
    empty.textContent = 'This could not be loaded just now. Reload the page to try again.';
    return;
  }
  const items = (result.value.data || []).slice(0, SHOWN);
  if (!items.length) return;
  list.replaceChildren(...items.map((item, i) => { const li = row(item); li.style.setProperty('--i', String(i)); return li; }));
  list.hidden = false;
  empty.hidden = true;
}
function item(title, sub, when) {
  const row = el('div', 'li');
  const left = el('div');
  left.append(el('b', null, title));
  if (sub) left.append(el('small', null, sub));
  row.append(left, el('time', null, when));
  return row;
}

/* ---------- loading ---------- */
function query() {
  const p = new URLSearchParams();
  if (state.strategy) p.set('strategy', state.strategy);
  if (state.status) p.set('status', state.status);
  const q = p.toString();
  return `/backtests${q ? `?${q}` : ''}`;
}
const filtered = () => Boolean(state.strategy || state.status);

async function loadAll({ keepSelection = true } = {}) {
  const [me, runs, strategies, journal, everything] = await Promise.allSettled([
    get('/me'), get(query()), get('/strategies'), get('/journal-entries'), filtered() ? get('/backtests') : Promise.resolve(null),
  ]);

  if ([me, runs, strategies, journal, everything].some((r) => r.status === 'rejected' && r.reason.status === 401)) {
    clearToken();
    setSignedOut('Your session has expired.');
    return;
  }

  const unfiltered = filtered() ? (everything.status === 'fulfilled' ? everything.value : null) : (runs.status === 'fulfilled' ? runs.value : null);
  if (unfiltered) state.account = { total: unfiltered.total, latest: (unfiltered.data || [])[0] || null };

  renderFoot([
    ['Runs', unfiltered ? state.account.total : null],
    ['Strategies', strategies.status === 'fulfilled' ? strategies.value.total : null],
    ['Journal entries', journal.status === 'fulfilled' ? journal.value.total : null],
  ]);

  const pair = $('pair');
  pair.hidden = false;
  fillList('strategies', strategies, (s) => item(s.name, s.description || '', day(s.created_at)));
  fillList('journal', journal, (j) => item(j.title, j.symbol || '', day(j.created_at)));
  if (state.first) pair.classList.add('arrive');

  if (runs.status === 'rejected') {
    headline.textContent = 'Your backtests.';
    headlineSub.textContent = '';
    desk.hidden = true;
    $('instruments').hidden = false;
    const note = $('runsEmpty');
    note.textContent = 'This could not be loaded just now. Reload the page to try again.';
    note.hidden = false;
    $('firstRun').hidden = false;
    return;
  }

  state.runs = runs.value.data || [];
  state.total = runs.value.total || 0;

  if (!state.account.total && !filtered()) {
    // First run: the headline says so, the instruments are the rows.
    headline.textContent = 'No backtests on this account yet.';
    headlineSub.textContent = 'Run one and it is kept here, with the inputs that produced it.';
    desk.hidden = true;
    $('runsEmpty').hidden = true;
    const first = $('firstRun');
    if (!first.querySelector('.plate')) first.prepend(plate());
    first.hidden = false;
    $('instruments').hidden = false;
    return;
  }

  setHeadline(state.account.total, state.account.latest);
  $('instruments').hidden = true;
  $('firstRun').hidden = true;
  desk.hidden = false;

  if (!keepSelection || !state.runs.some((r) => r.id === state.sel)) {
    const wanted = Number(new URLSearchParams(window.location.search).get('run'));
    let fromUrl = state.first && state.runs.find((r) => r.id === wanted);
    if (state.first && wanted && !fromUrl) {
      // a run linked from the header search can be older than the 20 loaded: fetch that one run (the API scopes it to the account)
      try { fromUrl = await get(`/backtests/${wanted}`); state.runs.unshift(fromUrl); } catch { fromUrl = null; }
    }
    state.sel = (fromUrl || state.runs.find((r) => r.results) || state.runs[0] || {}).id ?? null;
  }
  if (state.first) desk.classList.add('arrive');
  drawRows();
  drawStage();
  if (state.first) {
    state.first = false;
    // the arrival is one orchestrated move; remove its hooks once it has played so later redraws stay quiet
    window.setTimeout(() => { desk.classList.remove('arrive'); $('pair').classList.remove('arrive'); }, 1400);
  }
}

/* ---------- wiring ---------- */
const filterStrategy = $('filterStrategy');
Object.entries(STRATEGY_NAMES).forEach(([k, v]) => filterStrategy.append(Object.assign(el('option', null, v), { value: k })));
filterStrategy.addEventListener('change', () => { state.strategy = filterStrategy.value; loadAll({ keepSelection: true }); });
$('filterStatus').addEventListener('change', (e) => { state.status = e.target.value; loadAll({ keepSelection: true }); });

rowsEl.addEventListener('click', (e) => {
  const a = e.target.closest('a');
  if (!a) return;
  e.preventDefault();
  select(Number(a.dataset.id));
  if (window.matchMedia('(max-width: 1100px)').matches) stage.scrollIntoView({ block: 'start', behavior: motion() ? 'smooth' : 'auto' });
});
rowsEl.addEventListener('keydown', (e) => {
  if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
  const rows = [...rowsEl.querySelectorAll('tr[id]')];
  const i = rows.findIndex((tr) => tr.contains(document.activeElement));
  const next = rows[i + (e.key === 'ArrowDown' ? 1 : -1)];
  if (next) {
    e.preventDefault();
    select(Number(next.id.slice(1)), { focus: true });
  }
});
document.querySelectorAll('thead button').forEach((b) => b.addEventListener('click', () => {
  const k = b.dataset.k;
  state.dir = state.order === k && state.dir === 'descending' ? 'ascending' : 'descending';
  state.order = k;
  document.querySelectorAll('thead th').forEach((th) => th.setAttribute('aria-sort', 'none'));
  b.parentNode.setAttribute('aria-sort', state.dir);
  drawRows();
}));
let resizing;
window.addEventListener('resize', () => {
  window.clearTimeout(resizing);
  resizing = window.setTimeout(() => {
    const run = state.runs.find((r) => r.id === state.sel);
    const host = stage.querySelector('.chart');
    if (run && host && state.curves.has(run.id)) paintChart(run, host, false);
  }, 120);
});

async function start() {
  if (!isLoggedIn()) {
    setSignedOut('You are not logged in.');
    return;
  }
  await loadAll();
}
start();
