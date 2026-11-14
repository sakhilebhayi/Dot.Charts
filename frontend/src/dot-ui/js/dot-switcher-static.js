/*
 * dot-ui / switcher, static hosts (R-51). A framework-free renderer for the "Dot Apps" control: the SAME markup, the
 * same .dsw-* classes and the same data-dsw-* hooks as blade/switcher.blade.php, printed from a small JSON model in the
 * browser. Pair it with css/dot-switcher.css and js/dot-switcher.js (open/close, filter, "Opening...").
 *
 * An ES module with no dependencies and no globals. It never decides access: every tile is a link to
 * {infodot}/go/<key>, where InfoDot decides and signs the person in. It never writes a style attribute, never loads an
 * external asset (logos come from the host's own path) and builds markup only from escaped strings.
 *
 * Input model (what a host backend serves, or embeds in a <script type="application/json">):
 *   {
 *     current:       "charts",                       this platform's key
 *     infodot_url:   "https://infodot.co.za",
 *     platforms:     [{ key, name, entitled?, plain? }]   the registry (names); `entitled` only when a company answer exists
 *     state:         "degraded" | "ok" | "personal"  default "degraded" (nothing was checked)
 *     company:       uuid | null                     only with state "ok"
 *     company_name:  string | null
 *     notice:        string                          the visible "could not check" line (default NOTICE)
 *     notice_detail: string                          what follows it (default: "These open through InfoDot, ...")
 *     sub:           string                          sentence under the title for the degraded state
 *   }
 * The constants come from ./dot-switcher-data.js, generated from data/categories.json (the one canonical copy PHP reads
 * too); static/tests/static.test.mjs fails if it drifts.
 */

import { NOTICE, PERSONAL_MESSAGE, CORE, PLAIN, INTERNAL, NAMES, CATEGORIES, PRIMARY_CATEGORY, LOGO_SIZES } from './dot-switcher-data.js';

// The data is generated (dot-switcher-data.js, from data/categories.json and the logo manifest): there is no copy here.
export { NOTICE, PERSONAL_MESSAGE, CORE, PLAIN, INTERNAL, NAMES, CATEGORIES, PRIMARY_CATEGORY, LOGO_SIZES };

const MAX_W = 112;
const MAX_H = 68;

const GRID = '<svg class="dsw-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
  [4, 10, 16].flatMap((y) => [4, 10, 16].map((x) => '<rect x="' + x + '" y="' + y + '" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/>')).join('') + '</svg>';
const SEARCH = '<svg class="dsw-filter__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>';

export function esc(value) {
  return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

export function logoSize(key) {
  const s = LOGO_SIZES[key];
  if (!s) return null;
  const scale = Math.min(MAX_W / s[0], MAX_H / s[1]);
  return [Math.round(s[0] * scale), Math.round(s[1] * scale)];
}

function cleanUuid(v) {
  v = typeof v === 'string' ? v.trim().toLowerCase() : '';
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(v) ? v : null;
}

/** The same view model as PlatformSwitcher::for(): state, groups[], more[], notice, message, count. */
export function buildModel(input) {
  const infodot = String(input.infodot_url || 'https://infodot.co.za').replace(/\/+$/, '');
  const current = String(input.current || '');
  const names = {};
  const rows = [];
  (Array.isArray(input.platforms) ? input.platforms : []).forEach((p) => {
    if (!p || typeof p.key !== 'string' || !/^[a-z0-9_-]{1,40}$/.test(p.key) || typeof p.name !== 'string' || !p.name.trim()) return;
    if (INTERNAL.indexOf(p.key) !== -1 || names[p.key]) return;
    names[p.key] = p.name.trim().slice(0, 60);
    rows.push({ key: p.key, name: names[p.key], entitled: p.entitled === true, plain: p.plain === true });
  });

  const company = cleanUuid(input.company);
  let state = input.state === 'ok' || input.state === 'personal' ? input.state : 'degraded';
  if (state === 'ok' && company === null) state = 'personal';

  const go = (key, co) => infodot + '/go/' + encodeURIComponent(key) + (co ? '?company=' + encodeURIComponent(co) : '');
  const tile = (key, name, co, isCurrent, plainFromAnswer) => {
    const plain = PLAIN.indexOf(key) !== -1 || plainFromAnswer;
    const home = key === 'infodot';
    const href = isCurrent ? null : home ? infodot + '/home' : plain ? go(key, null) : go(key, co);
    return { key, name, href, current: isCurrent, plain, via_infodot: !plain && !home, logo: LOGO_SIZES[key] ? key + '.png' : null };
  };

  const tiles = [];
  const more = [];
  let notice = null;
  let message = null;

  if (state === 'ok') {
    const seen = {};
    rows.forEach((r) => {
      seen[r.key] = true;
      const isCurrent = r.key === current;
      if (r.entitled || isCurrent) tiles.push(tile(r.key, r.name, company, isCurrent, r.plain));
      else more.push({ key: r.key, name: r.name, href: infodot + '/platforms/' + encodeURIComponent(r.key) });
    });
    if (!seen[current] && INTERNAL.indexOf(current) === -1 && current) tiles.unshift(tile(current, names[current] || NAMES[current] || current, company, true, false));
  } else if (state === 'degraded') {
    notice = typeof input.notice === 'string' && input.notice ? input.notice : NOTICE;
    CORE.forEach((key) => tiles.push(tile(key, names[key] || NAMES[key] || key, null, key === current, false)));
    // As in PlatformSwitcher::degraded(): a host that is not in the core set still says where the person is.
    if (current && CORE.indexOf(current) === -1 && INTERNAL.indexOf(current) === -1) tiles.push(tile(current, names[current] || NAMES[current] || current, null, true, false));
  } else {
    message = PERSONAL_MESSAGE;
  }

  const groups = {};
  tiles.forEach((t) => {
    const cat = PRIMARY_CATEGORY[t.key] || null;
    const g = cat ? CATEGORIES[cat] : ['MORE', 'platforms', 'More platforms'];
    const id = cat || 'more';
    groups[id] = groups[id] || { code: g[0], tone: g[1], name: g[2], tiles: [] };
    groups[id].tiles.push(t);
  });
  const ordered = Object.keys(CATEGORIES).concat(['more']).filter((id) => groups[id]).map((id) => groups[id]);

  return {
    state, current, company, infodot_url: infodot, platforms_url: infodot + '/platforms',
    notice, notice_detail: typeof input.notice_detail === 'string' ? input.notice_detail : null,
    sub: typeof input.sub === 'string' ? input.sub : null, company_name: input.company_name || null,
    message, count: tiles.length, groups: ordered, more,
  };
}

function tileHtml(t, logoBase) {
  const size = logoSize(t.key);
  const img = (lazy) => (t.logo && size
    ? '<img src="' + esc(logoBase + '/' + t.logo) + '" alt="' + esc(t.name) + '" width="' + size[0] + '" height="' + size[1] + '"' + (lazy ? ' loading="lazy"' : '') + ' decoding="async">'
    : '');
  const name = esc(t.name);
  let inner;
  if (t.href === null) {
    inner = '<div class="dsw-bay is-current" aria-current="page"><span class="dsw-bay__logo">' + img(false) + '</span><span class="dsw-bay__name">' + name + '</span><span class="dsw-bay__tag dsw-bay__tag--here">You are here</span></div>';
  } else {
    inner = '<a class="dsw-bay" href="' + esc(t.href) + '" data-dsw-go data-dsw-label="' + name + '" title="' + esc(t.via_infodot ? 'Open ' + t.name + ' through InfoDot' : 'Open ' + t.name) + '">' +
      '<span class="dsw-bay__logo">' + img(true) + '</span><span class="dsw-bay__name">' + name + '</span>' +
      (t.plain ? '<span class="dsw-bay__tag dsw-bay__tag--own" title="Has its own sign-in">Own sign-in</span>' : '') +
      '<span class="dsw-bay__tag dsw-bay__tag--busy" aria-hidden="true">Opening&hellip;</span></a>';
  }
  return '<li data-dsw-name="' + esc(t.name.toLowerCase()) + '">' + inner + '</li>';
}

/** The inside of the `.dsw` root: fallback link, trigger and panel. Same markup as the Blade, byte for byte where it can be. */
export function renderInner(model, opts) {
  opts = opts || {};
  const id = opts.id || 'dsw-panel';
  const logoBase = String(opts.logos || '/img/platforms').replace(/\/+$/, '');
  const count = model.count;
  let sub;
  if (model.state === 'ok') sub = (count === 1 ? 'The one platform' : 'The ' + count + ' platforms') + ' ' + (model.company_name || 'your company') + ' has. Each opens already signed in.';
  else if (model.state === 'degraded') sub = model.sub || 'The core Dot platforms, opened through InfoDot.';
  else sub = 'Platforms are switched on per company.';

  let h = '<a class="dsw-fallback" href="' + esc(model.platforms_url) + '" title="Switch Dot platform">' + GRID + '<span class="dsw-btn__label">Dot Apps</span></a>' +
    '<button type="button" class="dsw-btn" data-dsw-trigger aria-expanded="false" aria-controls="' + esc(id) + '" title="Switch Dot platform">' + GRID + '<span class="dsw-btn__label">Dot Apps</span></button>' +
    '<div id="' + esc(id) + '" class="dsw-panel" data-dsw-panel role="region" aria-label="Dot platforms" hidden><div class="dsw-in"><div class="dsw-head"><div><p class="dsw-title">Dot Apps</p><p class="dsw-sub">' +
    esc(sub) + ' <a href="' + esc(model.platforms_url) + '" class="dsw-all">See all platforms</a></p></div>' +
    (count > 0 ? '<label class="dsw-filter"><span class="dsw-sr">Filter platforms</span>' + SEARCH + '<input type="search" data-dsw-filter placeholder="Filter platforms" autocomplete="off"></label>' : '') + '</div>';

  if (model.state === 'degraded') {
    h += '<p class="dsw-notice" role="status"><strong>' + esc(model.notice) + '.</strong> <span>' + esc(model.notice_detail || 'These open through InfoDot, which checks your access.') + '</span></p>';
  }
  if (model.state === 'personal') {
    h += '<p class="dsw-personal">' + esc(model.message) + '</p>';
  } else {
    h += '<p class="dsw-empty" data-dsw-empty role="status" hidden>No platform matches &ldquo;<span data-dsw-empty-q></span>&rdquo;.</p><div class="dsw-groups">';
    model.groups.forEach((g) => {
      h += '<section class="dsw-bays" data-tone="' + esc(g.tone) + '" data-dsw-group><h3 class="dsw-bays__head"><span class="dsw-bays__code">' + esc(g.code) + '</span><span class="dsw-bays__name">' + esc(g.name) + '</span></h3><ul class="dsw-bays__grid">' +
        g.tiles.map((t) => tileHtml(t, logoBase)).join('') + '</ul></section>';
    });
    h += '</div>';
    if (model.more.length > 0) {
      h += '<details class="dsw-more" data-dsw-more><summary>Not switched on (' + model.more.length + ')</summary><p class="dsw-more__note">Your company has not switched these on. Each link shows what the platform does and how to add it.</p><ul>' +
        model.more.map((o) => '<li data-dsw-name="' + esc(o.name.toLowerCase()) + '"><a href="' + esc(o.href) + '">' + esc(o.name) + '</a></li>').join('') + '</ul></details>';
    }
  }
  return h + '<p class="dsw-sr" data-dsw-live role="status" aria-live="polite"></p></div></div>';
}

// ------------------------------------------------------------------------------------------------ mounting

/**
 * Fills `root` (an element that already has class "dsw") from a model and starts the behaviour.
 * `behaviour` is window.DotSwitcher (js/dot-switcher.js) or anything with init(root).
 */
export function mount(root, input, opts) {
  opts = opts || {};
  const model = buildModel(input);
  root.innerHTML = renderInner(model, opts);
  root.setAttribute('data-dsw', '');
  root.setAttribute('data-state', model.state);
  root.setAttribute('data-platform', model.current);
  const behaviour = opts.behaviour || (typeof window !== 'undefined' ? window.DotSwitcher : null);
  if (behaviour && typeof behaviour.init === 'function') behaviour.init(root);
  return model;
}

/**
 * Reads a model from the page: `data-dsw-model` names a <script type="application/json"> holding it, otherwise
 * `data-dsw-url` is fetched (same origin, no credentials). Resolves the model or null; never throws.
 */
export async function loadModel(root, fetchImpl) {
  try {
    const embedded = root.getAttribute('data-dsw-model');
    if (embedded) {
      const el = root.ownerDocument.getElementById(embedded);
      return el ? JSON.parse(el.textContent) : null;
    }
    const url = root.getAttribute('data-dsw-url');
    if (!url) return null;
    const res = await (fetchImpl || fetch)(url, { headers: { Accept: 'application/json' }, credentials: 'omit' });
    return res.ok ? await res.json() : null;
  } catch (e) {
    return null;
  }
}

/** Mounts every `[data-dsw-static]` root on the page. On a failed load the plain link to InfoDot's platforms page stays. */
export async function mountAll(opts, doc) {
  doc = doc || document;
  const roots = Array.prototype.slice.call(doc.querySelectorAll('[data-dsw-static]'));
  await Promise.all(roots.map(async (root) => {
    if (root.getAttribute('data-dsw') !== null) return;
    const input = await loadModel(root, opts && opts.fetch);
    if (input) mount(root, input, Object.assign({ logos: root.getAttribute('data-dsw-logos') || undefined }, opts));
  }));
}
