// Home dashboard checks (run: npm test). No browser, no dependencies.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const navPages = ['home', 'index', 'backtest', 'history', 'journal', 'options', 'strategy-builder'];
const PAGE_KEY = { home: 'home', index: 'chart', backtest: 'backtest', history: 'history', journal: 'journal', options: 'options', 'strategy-builder': 'strategy' };

test('Home and Chart read are the first two links of the one nav model', async () => {
  const { navModel } = await import('../src/labels.js');
  const nav = navModel('home');
  assert.deepEqual(nav.slice(0, 2).map((i) => i.href), ['/home.html', '/']);
  // seven pages in all: four visible entries (two pages, two groups) instead of seven loose links
  assert.equal(nav.length, 4);
  assert.deepEqual(nav.slice(2).map((g) => g.label), ['Tools', 'Records']);
  const hrefs = nav.flatMap((i) => (i.items ? i.items.map((s) => s.href) : [i.href]));
  assert.equal(new Set(hrefs).size, 7);
});

test('exactly one page marks itself current in the nav, and the page names its own key', async () => {
  const { navModel } = await import('../src/labels.js');
  for (const p of navPages) {
    const html = read(`${p}.html`);
    assert.match(html, new RegExp(`<div id="app-header" data-page="${PAGE_KEY[p]}"`), p);
    const flat = navModel(PAGE_KEY[p]).flatMap((i) => i.items || [i]);
    assert.equal(flat.filter((i) => i.current).length, 1, p);
  }
});

test('the dashboard has one primary action, no inline style, no inline script and no external script', () => {
  const html = read('home.html');
  assert.equal((html.match(/data-primary-action/g) || []).length, 1);
  assert.doesNotMatch(html, /\sstyle\s*=/i);
  assert.doesNotMatch(html, /<script(?![^>]*\ssrc=)/i);
  assert.doesNotMatch(html, /<script[^>]*src="https?:/i);
  for (const f of ['src/home.js', 'src/equity-chart.js']) {
    assert.doesNotMatch(read(f), /setAttribute\(\s*['"]style|\.innerHTML\s*=|insertAdjacentHTML|https?:\/\/(?!www\.w3\.org)/, f);
  }
});

test('every animation in home.css sits inside prefers-reduced-motion: no-preference and a reduce rule removes them', () => {
  const css = read('src/home.css');
  const start = css.indexOf('@media (prefers-reduced-motion: no-preference)');
  assert.ok(start > 0);
  const outside = css.slice(0, start) + css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'));
  assert.doesNotMatch(outside.replace(/(animation|transition): none !important/g, ''), /animation\s*:|transition\s*:/);
  assert.match(css, /prefers-reduced-motion: reduce\)[\s\S]*animation: none !important/);
  // transform and opacity only
  const frames = [...css.matchAll(/@keyframes [\w-]+ \{([^}]*\}[^}]*)\}/g)].map((m) => m[1]).join(' ');
  assert.doesNotMatch(frames, /(width|height|top|left|margin|padding)\s*:/);
});

test('the chart colour is the validated one, not the logo blue', () => {
  const css = read('src/home.css');
  assert.match(css, /--series:\s*#2f8fe6/);
  assert.doesNotMatch(css.replace(/\/\*[\s\S]*?\*\//g, ''), /#8ec9dc/i);
});
