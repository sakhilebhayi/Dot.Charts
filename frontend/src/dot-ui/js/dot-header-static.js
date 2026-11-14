/*
 * dot-ui header, static renderer (0.5.0): prints the header, the nav menus, the account menu, the drawer and the search pill as DOM
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
 *   search     { name, id, placeholder, label, hint, action, value, results: <Node>, loadingText, loading, escapeClears }  or a Node
 *   tools      [Node, ...]                    the switcher mount, a bell (see bell())
 *   account    { name, email, team, initials, avatar, links, signOut: { action, method, label }, csrf, theme }
 *   drawer     { id: 'dh-drawer', side: 'right', title: 'Menu', target: null }   target = id of a platform's own nav (adopt mode)
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

/** <x-dot.bell> */
export function bell(doc, { href = '#', count: n = 0, label = 'Notifications', max = 99 } = {}) {
    const num = Number.isFinite(Number(n)) ? Math.trunc(Number(n)) : 0;
    const a = el(doc, 'a', { class: 'dh-icon-btn dh-bell', href, 'aria-label': num > 0 ? `${label}, ${num} unread` : label });
    a.appendChild(svg(doc, ICON.bell));
    if (num > 0) { a.appendChild(el(doc, 'span', { class: 'dh-badge', 'aria-hidden': 'true' }, num > max ? `${max}+` : String(num))); }
    return a;
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
    const links = (a.links || []).map((l) => (l.rule ? el(doc, 'div', { class: 'dh-menu__rule', role: 'separator' }) : el(doc, 'a', itemAttrs(l, 'dh-menu__item'), [el(doc, 'span', { class: 'dh-menu__label' }, l.label || ''), count(doc, l.badge)])));
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
export function drawer(doc, { id = 'dh-drawer', side = 'right', title = 'Menu', closeLabel = 'Close menu', nav = [], context = null, account = null, before = null, after = null, body = null } = {}) {
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
                before,
                body || el(doc, 'nav', { class: 'dh-dnav', 'aria-label': title }, list),
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
        class: o.hint ? 'dsr' : 'dsr dsr--nohint', 'data-dsr': true, 'data-dsr-shortcut': o.hint ? true : null,
        'data-dsr-loading': o.loading ? true : null, 'data-dsr-escape-clear': o.escapeClears ? true : null,
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

    const navItems = nav.map((item) => (item.items && item.items.length ? navMenu(doc, { label: item.label, items: item.items }) : el(doc, 'li', { class: 'dh-nav__item' }, el(doc, 'a', itemAttrs(item, 'dh-nav__link'), [item.label || '', count(doc, item.badge)]))));
    const more = el(doc, 'li', { class: 'dh-nav__item dh-more', 'data-dh-menu': true, 'data-dh-more': true, 'data-dh-label': m.moreLabel || 'More', hidden: true }, [
        el(doc, 'button', { type: 'button', class: 'dh-nav__link', 'data-dh-menu-trigger': true, 'aria-expanded': 'false', 'aria-controls': `${id}-more` }, [m.moreLabel || 'More', chev(doc)]),
        el(doc, 'div', { id: `${id}-more`, class: 'dh-menu', 'data-dh-menu-panel': true, hidden: true }, el(doc, 'ul', { class: 'dh-more__list', 'data-dh-more-list': true })),
    ]);

    const header = el(doc, 'header', { class: ['dh', searchNode ? '' : 'dh--nosearch', m.sticky === false ? 'dh--static' : ''].filter(Boolean).join(' '), id, 'data-dh': true }, [
        m.skip ? el(doc, 'a', { class: 'dh-skip skip-link', href: m.skip }, 'Skip to content') : null,
        el(doc, 'div', { class: 'dh-bar' }, [
            el(doc, 'div', { class: 'dh-start' }, [
                el(doc, 'a', { class: 'dh-brand', href: brand.href, 'aria-label': brand.label }, [brand.node || null, brand.name ? el(doc, 'span', { class: 'dh-brand__name' }, brand.name) : null]),
                m.context ? el(doc, 'div', { class: 'dh-context' }, el(doc, 'span', { class: 'dh-context__text', title: m.context }, m.context)) : null,
                el(doc, 'nav', { class: 'dh-nav', 'aria-label': m.navLabel || 'Primary', 'data-dh-nav': true }, el(doc, 'ul', { class: 'dh-nav__list' }, [...navItems, more])),
            ]),
            searchNode ? el(doc, 'div', { class: 'dh-search', id: searchId, 'data-dh-search': true }, [
                searchNode,
                el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-search__close', 'data-dh-search-close': true, 'aria-label': labels.closeSearch }, svg(doc, ICON.back)),
            ]) : null,
            el(doc, 'div', { class: 'dh-end' }, [
                searchNode ? el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-search-btn', 'data-dh-search-toggle': true, 'aria-expanded': 'false', 'aria-controls': searchId, 'aria-label': labels.search }, svg(doc, [{ circle: { cx: 11, cy: 11, r: 6.5 } }, 'M16 16l4.5 4.5'])) : null,
                ...(m.tools || []),
                m.account ? accountMenu(doc, m.account) : null,
                el(doc, 'button', { type: 'button', class: 'dh-icon-btn dh-menu-btn', 'data-dh-menu-button': true, 'aria-expanded': 'false', 'aria-controls': dr.target || dr.id, 'aria-label': labels.menu }, svg(doc, ICON.menu)),
            ]),
        ]),
    ]);

    return { header, drawer: dr.target ? null : drawer(doc, { id: dr.id, side: dr.side, title: dr.title, closeLabel: labels.closeMenu, nav, context: m.context || null, account: m.account || null }) };
}

/** Replaces the children of `host` with the header and puts the drawer right after it (a sibling, never inside the header). */
export function mountHeader(host, model, doc = document) {
    const { header, drawer: d } = buildHeader(model, doc);
    host.replaceChildren(header);
    if (d) { host.appendChild(d); }
    return { header, drawer: d };
}
