// The header search (the shared dot-ui pill, InfoDot's states) over what Dot.Charts really has.
//
//  Signed in:  GET /api/search?q=  returns the account's OWN backtests, journal entries and saved strategies (account-scoped,
//              five per group, parameterised: backend/app/Http/Controllers/SearchController.php). The platform's pages are
//              matched locally.
//  Signed out: there is no account data to search. The pill says so ("Sign in to search ..."), still finds the platform's
//              pages and tools, and makes no request. A visitor never receives another account's data: the endpoint answers
//              401 without a token.
//
// States, as InfoDot's: empty box (Go to chips and a hint), typed (groups, <mark> highlight), loading (one line in the
// dropdown, never on the pill), no match, unavailable. Arrow keys move a highlight, Enter opens it, Escape clears and closes.
import { API_BASE } from './api-base.js';
import { getToken, clearToken } from './auth.js';
import { STRATEGY_NAMES, PAGES } from './labels.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const MIN = 2;
const WAIT = 220;

const ICON = {
  run: 'M4 19V5M4 19h16M8 15l3-4 3 2 5-6',
  note: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h5',
  rule: 'M5 7h14M5 12h9M5 17h5M17 15l2 2 3-4',
  page: 'M5 4h14v16H5zM9 9h6M9 13h6',
};

function icon(kind) {
  const s = document.createElementNS(SVG_NS, 'svg');
  s.setAttribute('class', 'dsr-icon');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  const p = document.createElementNS(SVG_NS, 'path');
  p.setAttribute('d', ICON[kind]);
  s.appendChild(p);
  return s;
}

function span(cls, text) {
  const n = document.createElement('span');
  n.className = cls;
  if (text !== undefined) n.textContent = text;
  return n;
}

// text with the query highlighted, built as nodes (records are user text: never innerHTML)
function marked(text, q) {
  const out = span('dsr-label');
  const t = String(text);
  const at = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
  if (at < 0) {
    out.textContent = t;
    return out;
  }
  const mark = document.createElement('mark');
  mark.textContent = t.slice(at, at + q.length);
  out.append(t.slice(0, at), mark, t.slice(at + q.length));
  return out;
}

const month = (v) => {
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
};

export function createSearch({ signedIn, onUnauthorized }) {
  const results = document.createElement('div');
  results.className = 'dsr-quick';
  let optionId = 0;
  let timer = 0;
  let ctrl = null;
  let serial = 0;
  let root = null;
  let input = null;

  const head = (text) => { const h = document.createElement('div'); h.className = 'dsr-head'; h.textContent = text; return h; };
  const foot = (...kids) => { const p = document.createElement('p'); p.className = 'dsr-foot'; p.setAttribute('role', 'status'); p.append(...kids); return p; };

  function item({ href, kind, ref, label, tag, q, action }) {
    const a = document.createElement('a');
    a.className = action ? 'dsr-item dsr-item--action' : 'dsr-item';
    a.href = href;
    a.setAttribute('role', 'option');
    a.setAttribute('aria-selected', 'false');
    a.id = `hs-opt-${(optionId += 1)}`;
    if (ref) a.append(span('dsr-ref', ref)); else a.append(icon(kind));
    a.append(marked(label, q));
    if (tag) a.append(span('dsr-tag', tag));
    return a;
  }

  function pageMatches(q) {
    const needle = q.toLowerCase();
    return PAGES.filter((p) => !needle || p.label.toLowerCase().includes(needle) || p.about.toLowerCase().includes(needle));
  }

  function chips() {
    const wrap = document.createElement('div');
    wrap.className = 'dsr-chips';
    PAGES.forEach((p) => {
      const a = document.createElement('a');
      a.className = 'dsr-chip';
      a.href = p.href;
      a.setAttribute('role', 'option');
      a.setAttribute('aria-selected', 'false');
      a.id = `hs-opt-${(optionId += 1)}`;
      a.textContent = p.label;
      wrap.appendChild(a);
    });
    return wrap;
  }

  function signInNote() {
    const a = document.createElement('a');
    a.href = '/login.html';
    a.textContent = 'Log in';
    return foot('Search is limited to the pages here. ', a, ' to search your backtests, journal entries and strategies.');
  }

  function paint(...nodes) {
    results.replaceChildren(...nodes);
    clearActive();
  }

  function renderEmpty() {
    paint(
      head('Go to'),
      chips(),
      signedIn
        ? foot('Type a symbol, a strategy name or a word from a note to search your account.')
        : signInNote(),
    );
  }

  function pagesBlock(q) {
    const hits = pageMatches(q);
    if (!hits.length) return [];
    return [head('Pages'), ...hits.map((p) => item({ href: p.href, kind: 'page', label: p.label, tag: 'Page', q }))];
  }

  function renderTyped(q, data, state) {
    const nodes = [];
    if (data) {
      const runs = data.backtests || [];
      const rules = data.strategies || [];
      const notes = data.journal || [];
      if (runs.length) {
        nodes.push(head('Backtests'));
        runs.forEach((r) => nodes.push(item({
          href: `/home.html?run=${encodeURIComponent(r.id)}`,
          ref: r.symbol,
          label: `${STRATEGY_NAMES[r.strategy] || r.strategy}, ${month(r.start_date)} to ${month(r.end_date)}`,
          tag: r.status === 'complete' ? null : r.status,
          q,
        })));
      }
      if (rules.length) {
        nodes.push(head('Strategies'));
        rules.forEach((s) => nodes.push(item({ href: `/strategy-builder.html?strategy=${encodeURIComponent(s.id)}`, kind: 'rule', label: s.name, q })));
      }
      if (notes.length) {
        nodes.push(head('Journal'));
        notes.forEach((n) => nodes.push(item({ href: `/journal.html?entry=${encodeURIComponent(n.id)}`, kind: 'note', label: n.title, tag: n.symbol || null, q })));
      }
    }
    nodes.push(...pagesBlock(q));
    const found = nodes.some((n) => n.classList && n.classList.contains('dsr-item'));
    if (state === 'error') nodes.push(foot('Your account could not be searched just now. The pages above still work.'));
    else if (state === 'expired') nodes.push(foot('Your session has expired. Log in again to search your account.'));
    else if (state === 'done' && !found) nodes.push(foot(`Nothing matches “${q}”. Try a symbol, a strategy name or a word from a note.`));
    else if (!signedIn && !found) nodes.push(foot(`No page matches “${q}”.`), signInNote());
    else if (!signedIn) nodes.push(signInNote());
    paint(...nodes);
  }

  function loading(on) {
    if (!root) return;
    root.toggleAttribute('data-dsr-loading', on);
  }

  async function ask(q) {
    const mine = (serial += 1);
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    loading(true);
    try {
      const res = await fetch(`${API_BASE}/search?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
        signal: ctrl.signal,
        credentials: 'omit',
      });
      if (mine !== serial) return;
      if (res.status === 401) {
        clearToken();
        loading(false);
        renderTyped(q, null, 'expired');
        if (onUnauthorized) onUnauthorized();
        return;
      }
      if (!res.ok) throw new Error('unavailable');
      const data = await res.json();
      if (mine !== serial) return;
      loading(false);
      renderTyped(q, data, 'done');
    } catch (error) {
      if (error.name === 'AbortError' || mine !== serial) return;
      loading(false);
      renderTyped(q, null, 'error');
    }
  }

  function onInput() {
    const q = input.value.trim();
    window.clearTimeout(timer);
    if (ctrl) { ctrl.abort(); ctrl = null; }
    serial += 1;
    loading(false);
    if (q.length < MIN) { renderEmpty(); return; }
    if (!signedIn) { renderTyped(q, null, 'pages'); return; }
    renderTyped(q, null, 'wait'); // the page matches at once; the account's answer follows
    loading(true);
    timer = window.setTimeout(() => ask(q), WAIT);
  }

  /* ---- keyboard: the highlight is the host's (dot-ui styles .is-active) ---- */
  const options = () => [...results.querySelectorAll('.dsr-item, .dsr-chip')];
  function clearActive() {
    results.querySelectorAll('.is-active').forEach((n) => { n.classList.remove('is-active'); n.setAttribute('aria-selected', 'false'); });
    if (input) input.removeAttribute('aria-activedescendant');
  }
  function move(step) {
    const list = options();
    if (!list.length) return;
    const at = list.findIndex((n) => n.classList.contains('is-active'));
    const next = at < 0 ? (step > 0 ? 0 : list.length - 1) : (at + step + list.length) % list.length;
    clearActive();
    list[next].classList.add('is-active');
    list[next].setAttribute('aria-selected', 'true');
    input.setAttribute('aria-activedescendant', list[next].id);
    list[next].scrollIntoView({ block: 'nearest' });
  }

  function attach(host) {
    root = host.querySelector('.dsr[data-dsr]');
    input = root && root.querySelector('.dsr-input');
    if (!root || !input) return;
    input.addEventListener('input', onInput);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    });
    // Enter opens the highlighted row, else the first real result (never a guess at a page for a symbol).
    root.querySelector('form').addEventListener('submit', (e) => {
      e.preventDefault();
      const list = options();
      const target = list.find((n) => n.classList.contains('is-active')) || (input.value.trim().length >= MIN ? list[0] : null);
      if (target) window.location.assign(target.href);
    });
  }

  renderEmpty();
  return { results, attach };
}
