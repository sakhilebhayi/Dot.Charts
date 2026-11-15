/*
 * dot-ui header, static renderer (0.6.0): prints the header, the nav menus, the account menu, the drawer and the search pill as DOM
 * nodes, for hosts that are plain HTML + vanilla JS (Dot.Charts). The markup is the Blade's (blade/header.blade.php and its parts), so
 * css/dot-header.css, css/dot-search.css and js/dot-nav.js work unchanged. Plain ES module: no framework, no innerHTML (text is set with
 * textContent), no inline style, no inline handlers, no eval: strict-CSP safe.
 *
 *   import { mountHeader } from './dot-ui/js/dot-header-static.js';
 *   mountHeader(document.getElementById('app-header'), model);   // then load js/dot-nav.js (and dot-search.js, dot-switcher.js)
 *
 * model (every key optional except brand):
 *   brand      { href: '/', label: 'Home', node: <svg|img element>, name: 'Dot.Charts' }   the platform's own logo
 *   context    'Ledger Works'                 short company name (wide screens); the full name is in the account menu
 *   nav        [{ label, href, current, badge, attrs }, { label, items: [...] }]           same shape as the Blade `nav` prop
 *   moreLabel  'More'      navLabel 'Primary'
 *   search     { name, id, placeholder, label, hint, hintKey, resultsNav, enterFirst, action, value, results: <Node>, loadingText, loading, escapeClears }  or a Node
 *   status     <Node>                         an always-visible readout beside the brand (0.6.0)
 *   tools      [Node, ...]                    the switcher mount, a bell (see bell())
 *   account    { name, email, team, initials, avatar, links, signOut: { action, method, label }, csrf, theme }
 *              links: { label, href, badge, current, attrs } | { rule } | { heading } | { label, form: { action, method, fields } } | { label, button: true, attrs }
 *   guest      { login: { label, href }, register: { label, href } }   the signed-out state (0.6.0), instead of `account`; or a Node
 *   drawer     { id: 'dh-drawer', side: 'right', title: 'Menu', target: null, before: <Node>, after: <Node>, body: <Node>, guest: <Node> }   target = id of a platform's own nav (adopt mode)
 *   labels     { menu, search, closeSearch, closeMenu }
 *   id         'dh'    skip  '#main'     sticky  true
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

function el(doc, tag, attrs, kids) {
    const n = doc.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) { continue; }
        n.setAttribute(k, v === true ? '' : String(v));
    }
    for (const kid of [].concat(kids === undefined ? [] : kids)) {
        if (kid === null || kid === undefined || kid === false) { continue; }
        n.appendChild(typeof kid === 'string' ? doc.createTextNode(kid) : kid);
    }
    return n;
}

function svg(doc, paths) {
    const s = doc.createElementNS(SVG_NS, 'svg');
    s.setAttribute('class', 'dh-ic');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    for (const d of paths) {
        const p = doc.createElementNS(SVG_NS, d.circle ? 'circle' : 'path');
        for (const [k, v] of Object.entries(d.circle || { d: d })) { p.setAttribute(k, String(v)); }
        s.appendChild(p);
    }
    return s;
}

const ICON = {
    menu: ['M4 7h16M4 12h16M4 17h16'],
    close: ['M6 6l12 12M18 6L6 18'],
    back: ['M15 5l-7 7 7 7'],
    bell: ['M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4'],
};

const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
const has = (v) => v !== undefined && v !== null && v !== '';
const chev = (doc) => el(doc, 'span', { class: 'dh-chev', 'aria-hidden': 'true' });

function itemAttrs(item, cls) {
    return Object.assign({ class: cls, href: item.href || '#', 'aria-current': item.current ? 'page' : null }, item.attrs || {});
}

function count(doc, badge) {
    return has(badge) ? el(doc, 'span', { class: 'dh-count' }, String(badge)) : null;
}

/** <x-dot.bell>: a link, or (with `panel`, a Node) a button that opens the panel (0.6.0). `always` keeps the link bell in the bar under 480px. */
export function bell(doc, { href = '#', count: n = 0, label = 'Notifications', max = 99, always = false, panel = null, id = 'dh-bell-panel', panelLabel = null } = {}) {
    const num = Number.isFinite(Number(n)) ? Math.trunc(Number(n)) : 0;
    const name = num > 0 ? `${label}, ${num} unread` : label;
    const badge = num > 0 ? el(doc, 'span', { class: 'dh-badge', 'aria-hidden': 'true' }, num > max ? `${max}+` : String(num)) : null;
    if (panel) {
        return el(doc, 'div', { class: 'dh-bellwrap', 'data-dh-menu': true, 'data-dh-bell': true }, [
            el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-bell dh-bell--panel', 'data-dh-menu-trigger': true, 'aria-expanded': 'false', 'aria-controls': id, 'aria-haspopup': 'true', 'aria-label': name }, [svg(doc, ICON.bell), badge]),
            el(doc, 'div', { id, class: 'dh-menu dh-menu--end dh-bellpanel', role: 'region', 'aria-label': panelLabel || label, 'data-dh-menu-panel': true, hidden: true }, panel),
        ]);
    }
    return el(doc, 'a', { class: always ? 'dh-icon-btn dh-bell dh-bell--always' : 'dh-icon-btn dh-bell', href, 'aria-label': name }, [svg(doc, ICON.bell), badge]);
}

/** <x-dot.guest>: the signed-out pair (0.6.0). `inline` is the drawer's stacked form. */
export function guestMenu(doc, { login = null, register = null, inline = false, label = 'Account' } = {}) {
    const one = (item, text) => (item && typeof item === 'object' ? Object.assign({ label: text, href: '#', attrs: {} }, item) : null);
    const a = one(login, 'Log in');
    const b = one(register, 'Create account');
    if (!a && !b) { return null; }
    return el(doc, 'div', { class: inline ? 'dh-dguest' : 'dh-guest', role: 'group', 'aria-label': label }, [
        a ? el(doc, 'a', Object.assign({ class: 'dh-guest__link', href: a.href }, a.attrs), a.label) : null,
        b ? el(doc, 'a', Object.assign({ class: 'dh-guest__link dh-guest__link--primary', href: b.href }, b.attrs), b.label) : null,
    ]);
}

const CHECK = ['M5 12.5l4.5 4.5L19 7.5'];

/** <x-dot.menu-item>: one account-menu row: a link, a divider, a heading, a one-button form or a plain button (0.6.0). */
export function menuItem(doc, item, csrf = null) {
    if (item.rule) { return el(doc, 'div', { class: 'dh-menu__rule', role: 'separator' }); }
    const form = item.form && typeof item.form === 'object' ? item.form : null;
    if (item.heading && !item.href && !form && !item.button) { return el(doc, 'p', { class: 'dh-menu__head' }, item.heading); }
    const mark = item.current && (form || item.button) ? (() => { const c = svg(doc, CHECK); c.setAttribute('class', 'dh-menu__mark'); return c; })() : null;
    const body = [el(doc, 'span', { class: 'dh-menu__label' }, item.label || ''), mark, count(doc, item.badge)];
    if (form) {
        const verb = String(form.method || 'POST').toUpperCase();
        const spoof = verb !== 'GET' && verb !== 'POST';
        return el(doc, 'form', { class: 'dh-menu__form', method: verb === 'GET' ? 'get' : 'post', action: form.action || '' }, [
            csrf && verb !== 'GET' && form.csrf !== false ? el(doc, 'input', { type: 'hidden', name: '_token', value: csrf }) : null,
            spoof ? el(doc, 'input', { type: 'hidden', name: '_method', value: verb }) : null,
            ...Object.entries(form.fields || {}).map(([n, v]) => el(doc, 'input', { type: 'hidden', name: n, value: v })),
            el(doc, 'button', Object.assign({ type: 'submit', class: 'dh-menu__item', 'aria-current': item.current ? 'true' : null }, item.attrs || {}), body),
        ]);
    }
    if (item.button) { return el(doc, 'button', Object.assign({ type: 'button', class: 'dh-menu__item', 'aria-current': item.current ? 'true' : null }, item.attrs || {}), body); }
    return el(doc, 'a', itemAttrs(item, 'dh-menu__item'), [el(doc, 'span', { class: 'dh-menu__label' }, item.label || ''), count(doc, item.badge)]);
}

/** <x-dot.nav-menu> */
export function navMenu(doc, { label, items = [], id = null, align = 'start' }) {
    const panelId = id || `dh-menu-${slug(label)}`;
    const current = items.some((i) => i && i.current);
    const panel = el(doc, 'div', { id: panelId, class: 'dh-menu', 'data-align': align === 'end' ? 'end' : null, 'data-dh-menu-panel': true, hidden: true });
    for (const item of items) {
        if (item.rule) { panel.appendChild(el(doc, 'div', { class: 'dh-menu__rule', role: 'separator' })); }
        else if (item.heading && !item.href) { panel.appendChild(el(doc, 'p', { class: 'dh-menu__head' }, item.heading)); }
        else { panel.appendChild(el(doc, 'a', itemAttrs(item, 'dh-menu__item'), [el(doc, 'span', { class: 'dh-menu__label' }, item.label || ''), count(doc, item.badge)])); }
    }
    return el(doc, 'li', { class: 'dh-nav__item', 'data-dh-menu': true, 'data-dh-label': label, 'data-has-current': current ? true : null }, [
        el(doc, 'button', { type: 'button', class: 'dh-nav__link', 'data-dh-menu-trigger': true, 'aria-expanded': 'false', 'aria-controls': panelId }, [label, chev(doc)]),
        panel,
    ]);
}

function themeGroup(doc) {
    return el(doc, 'div', { class: 'dh-theme', role: 'group', 'aria-label': 'Theme' }, ['light', 'dark', 'system'].map((m) =>
        el(doc, 'button', { type: 'button', class: 'dh-theme__btn', 'data-dh-theme-set': m, 'aria-pressed': 'false' }, m[0].toUpperCase() + m.slice(1))));
}

function signOutForm(doc, a) {
    const out = Object.assign({ method: 'post', label: 'Sign out' }, a.signOut);
    const get = String(out.method).toLowerCase() === 'get';
    return el(doc, 'form', { class: 'dh-signout', method: get ? 'get' : 'post', action: out.action }, [
        a.csrf && !get ? el(doc, 'input', { type: 'hidden', name: '_token', value: a.csrf }) : null,
        el(doc, 'button', { type: 'submit', class: 'dh-menu__item' }, out.label),
    ]);
}

function avatar(doc, a) {
    const letters = a.initials || String(a.name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('');
    return el(doc, 'span', { class: 'dh-avatar', 'aria-hidden': 'true' }, a.avatar ? el(doc, 'img', { src: a.avatar, alt: '', width: 34, height: 34 }) : letters);
}

/** <x-dot.account-menu> (inline: the expanded list for the drawer) */
export function accountMenu(doc, a, { inline = false, id = 'dh-account-menu', label = 'Account menu' } = {}) {
    const links = (a.links || []).map((l) => menuItem(doc, l, a.csrf || null));
    const who = [el(doc, 'span', { class: 'dh-menu__name', title: a.name || '' }, a.name || ''), a.email ? el(doc, 'span', { class: 'dh-menu__sub', title: a.email }, a.email) : null, a.team ? el(doc, 'span', { class: 'dh-menu__sub', title: a.team }, a.team) : null];
    if (inline) {
        return el(doc, 'section', { class: 'dh-dacct', 'aria-label': 'Account' }, [
            el(doc, 'div', { class: 'dh-dacct__who' }, [avatar(doc, a), el(doc, 'span', {}, who)]),
            ...links,
            a.theme ? themeGroup(doc) : null,
            a.signOut ? signOutForm(doc, a) : null,
        ]);
    }
    const rule = () => el(doc, 'div', { class: 'dh-menu__rule', role: 'separator' });
    return el(doc, 'div', { class: 'dh-account', 'data-dh-menu': true, 'data-dh-account': true }, [
        el(doc, 'button', { type: 'button', class: 'dh-account__btn', 'data-dh-menu-trigger': true, 'aria-expanded': 'false', 'aria-controls': id, 'aria-label': a.name ? `${label}: ${a.name}` : label }, avatar(doc, a)),
        el(doc, 'div', { id, class: 'dh-menu dh-menu--end', 'data-dh-menu-panel': true, hidden: true }, [
            el(doc, 'div', { class: 'dh-menu__who' }, who),
            rule(),
            ...links,
            a.theme ? rule() : null,
            a.theme ? themeGroup(doc) : null,
            a.signOut ? rule() : null,
            a.signOut ? signOutForm(doc, a) : null,
        ]),
    ]);
}

/** <x-dot.drawer> */
export function drawer(doc, { id = 'dh-drawer', side = 'right', title = 'Menu', closeLabel = 'Close menu', nav = [], context = null, account = null, guest = null, before = null, after = null, body = null } = {}) {
    let g = 0;
    const list = el(doc, 'ul', {});
    for (const item of nav) {
        if (item.items && item.items.length) {
            g += 1;
            const lid = `${id}-g${g}`;
            const open = !!item.open || item.items.some((s) => s && s.current);
            list.appendChild(el(doc, 'li', { class: 'dh-dgroup' }, [
                el(doc, 'button', { type: 'button', class: 'dh-dgroup__btn', 'data-dh-disclosure': true, 'aria-expanded': open ? 'true' : 'false', 'aria-controls': lid }, [el(doc, 'span', { class: 'dh-menu__label' }, item.label), chev(doc)]),
                el(doc, 'ul', { id: lid, class: 'dh-dgroup__list', hidden: !open }, item.items.filter((s) => s.href).map((s) => el(doc, 'li', {}, el(doc, 'a', itemAttrs(s, 'dh-dnav__link'), [el(doc, 'span', { class: 'dh-menu__label' }, s.label || ''), count(doc, s.badge)])))),
            ]));
        } else if (item.href) {
            list.appendChild(el(doc, 'li', {}, el(doc, 'a', itemAttrs(item, 'dh-dnav__link'), [el(doc, 'span', { class: 'dh-menu__label' }, item.label || ''), count(doc, item.badge)])));
        }
    }
    return el(doc, 'div', { class: 'dh-drawer', id, 'data-dh-drawer': true, 'data-side': side, hidden: true }, [
        el(doc, 'div', { class: 'dh-drawer__scrim', 'data-dh-scrim': true, 'aria-hidden': 'true' }),
        el(doc, 'div', { class: 'dh-drawer__panel', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, tabindex: '-1' }, [
            el(doc, 'div', { class: 'dh-drawer__head' }, [
                el(doc, 'span', { class: 'dh-drawer__title' }, title),
                el(doc, 'button', { type: 'button', class: 'dh-icon-btn', 'data-dh-close': true, 'aria-label': closeLabel }, svg(doc, ICON.close)),
            ]),
            el(doc, 'div', { class: 'dh-drawer__body' }, [
                context ? el(doc, 'p', { class: 'dh-drawer__context' }, context) : null,
                guest && guest.nodeType ? guest : (guest ? guestMenu(doc, Object.assign({ inline: true }, guest)) : null),
                before,
                body || (list.childNodes.length ? el(doc, 'nav', { class: 'dh-dnav', 'aria-label': title }, list) : null),
                account ? accountMenu(doc, account, { inline: true }) : null,
                after,
            ]),
        ]),
    ]);
}

/** The search pill: the markup of blade/search.blade.php. `results` is a Node the host fills (rows are the host's). */
export function searchPill(doc, o = {}) {
    const name = o.name || 'search';
    const id = o.id || `dsr-${slug(name) || 'search'}`;
    const listId = `${id}-list`;
    const hasPanel = !!o.results || !!o.loadingText;
    const input = el(doc, 'input', {
        class: 'dsr-input', id, type: 'search', name, value: o.value || '', placeholder: o.placeholder || 'Search', autocomplete: 'off',
        role: hasPanel ? 'combobox' : null, 'aria-autocomplete': hasPanel ? 'list' : null, 'aria-controls': hasPanel ? listId : null, 'aria-expanded': hasPanel ? 'false' : null,
    });
    const label = o.label || 'Search';
    const form = el(doc, 'form', { role: 'search', method: o.method || 'get', action: o.action || null }, [
        input,
        o.hint ? el(doc, 'kbd', { class: 'dsr-kbd', 'aria-hidden': 'true' }, o.hint) : null,
        el(doc, 'button', { type: 'submit', class: 'dsr-go', 'aria-label': label }, el(doc, 'span', { class: 'dsr-arrow', 'aria-hidden': 'true' })),
    ]);
    return el(doc, 'div', {
        class: o.hint ? 'dsr' : 'dsr dsr--nohint', 'data-dsr': true, 'data-dsr-shortcut': o.hint && o.hintKey !== false ? true : null,
        'data-dsr-loading': o.loading ? true : null, 'data-dsr-escape-clear': o.escapeClears ? true : null,
        'data-dsr-nav': o.resultsNav ? true : null, 'data-dsr-enter-first': o.resultsNav && o.enterFirst ? true : null,
    }, [
        el(doc, 'label', { for: id, class: 'dsr-sr' }, label),
        form,
        hasPanel ? el(doc, 'div', { id: listId, class: 'dsr-panel', role: 'listbox', 'aria-label': 'Suggestions', tabindex: '-1' }, [o.results || null, o.loadingText ? el(doc, 'p', { class: 'dsr-foot dsr-loading', 'aria-live': 'polite' }, o.loadingText) : null]) : null,
    ]);
}

/** <x-dot.header> + its drawer: returns { header, drawer } (drawer is null in adopt mode). */
export function buildHeader(model, doc = document) {
    const m = model || {};
    const id = m.id || 'dh';
    const nav = m.nav || [];
    const dr = Object.assign({ id: 'dh-drawer', side: 'right', title: 'Menu', target: null }, m.drawer);
    const labels = Object.assign({ menu: 'Menu', search: 'Search', closeSearch: 'Close search', closeMenu: 'Close menu' }, m.labels);
    const brand = Object.assign({ href: '/', label: 'Home' }, m.brand);
    const searchModel = m.search && m.search.nodeType ? null : m.search;
    const searchNode = m.search && m.search.nodeType ? m.search : (searchModel ? searchPill(doc, searchModel) : null);
    const searchId = `${id}-search`;

    const hasNav = nav.length > 0;
    const hasStatus = !!m.status;
    const guestNode = m.guest && m.guest.nodeType ? m.guest : (m.guest ? guestMenu(doc, m.guest) : null);
    const navItems = nav.map((item) => (item.items && item.items.length ? navMenu(doc, { label: item.label, items: item.items }) : el(doc, 'li', { class: 'dh-nav__item' }, el(doc, 'a', itemAttrs(item, 'dh-nav__link'), [item.label || '', count(doc, item.badge)]))));
    const more = el(doc, 'li', { class: 'dh-nav__item dh-more', 'data-dh-menu': true, 'data-dh-more': true, 'data-dh-label': m.moreLabel || 'More', hidden: true }, [
        el(doc, 'button', { type: 'button', class: 'dh-nav__link', 'data-dh-menu-trigger': true, 'aria-expanded': 'false', 'aria-controls': `${id}-more` }, [m.moreLabel || 'More', chev(doc)]),
        el(doc, 'div', { id: `${id}-more`, class: 'dh-menu', 'data-dh-menu-panel': true, hidden: true }, el(doc, 'ul', { class: 'dh-more__list', 'data-dh-more-list': true })),
    ]);

    const header = el(doc, 'header', { class: ['dh', searchNode ? '' : 'dh--nosearch', m.sticky === false ? 'dh--static' : '', hasNav ? '' : 'dh--nonav', dr.target ? 'dh--adopt' : ''].filter(Boolean).join(' '), id, 'data-dh': true }, [
        m.skip ? el(doc, 'a', { class: 'dh-skip skip-link', href: m.skip }, 'Skip to content') : null,
        el(doc, 'div', { class: 'dh-bar' }, [
            el(doc, 'div', { class: 'dh-start' }, [
                el(doc, 'a', { class: 'dh-brand', href: brand.href, 'aria-label': brand.label }, [brand.node || null, brand.name ? el(doc, 'span', { class: 'dh-brand__name' }, brand.name) : null]),
                m.context ? el(doc, 'div', { class: 'dh-context' }, el(doc, 'span', { class: 'dh-context__text', title: m.context }, m.context)) : null,
                hasStatus ? el(doc, 'div', { class: 'dh-status', 'data-dh-status': true }, m.status) : null,
                hasNav ? el(doc, 'nav', { class: 'dh-nav', 'aria-label': m.navLabel || 'Primary', 'data-dh-nav': true }, el(doc, 'ul', { class: 'dh-nav__list' }, [...navItems, more])) : null,
            ]),
            searchNode ? el(doc, 'div', { class: 'dh-search', id: searchId, 'data-dh-search': true }, [
                searchNode,
                el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-search__close', 'data-dh-search-close': true, 'aria-label': labels.closeSearch }, svg(doc, ICON.back)),
            ]) : null,
            el(doc, 'div', { class: 'dh-end' }, [
                searchNode ? el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-search-btn', 'data-dh-search-toggle': true, 'aria-expanded': 'false', 'aria-controls': searchId, 'aria-label': labels.search }, svg(doc, [{ circle: { cx: 11, cy: 11, r: 6.5 } }, 'M16 16l4.5 4.5'])) : null,
                ...(m.tools || []),
                m.account ? accountMenu(doc, m.account) : guestNode,
                el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-menu-btn', 'data-dh-menu-button': true, 'aria-expanded': 'false', 'aria-controls': dr.target || dr.id, 'aria-label': labels.menu }, svg(doc, ICON.menu)),
            ]),
        ]),
    ]);

    return { header, drawer: dr.target ? null : drawer(doc, { id: dr.id, side: dr.side, title: dr.title, closeLabel: labels.closeMenu, nav, context: m.context || null, account: m.account || null, guest: m.account ? null : (dr.guest || m.guest || null), before: dr.before || null, after: dr.after || null, body: dr.body || null }) };
}

/** Replaces the children of `host` with the header and puts the drawer right after it (a sibling, never inside the header). */
export function mountHeader(host, model, doc = document) {
    const { header, drawer: d } = buildHeader(model, doc);
    host.replaceChildren(header);
    if (d) { host.appendChild(d); }
    return { header, drawer: d };
}
