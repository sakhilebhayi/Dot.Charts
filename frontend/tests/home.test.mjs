// Home dashboard checks (run: npm test). No browser, no dependencies.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const navPages = ['home', 'index', 'backtest', 'history', 'journal', 'options', 'strategy-builder'];
const navLinks = (html) => [...html.match(/<header class="bar">[\s\S]*?<\/header>/)[0].matchAll(/<a href="([^"]+)"[^>]*>([^<]+)<\/a>/g)].map((m) => m[1]);

test('Home and Chart read are the first two links in every page that has the instrument nav', () => {
  for (const p of navPages) {
    const links = navLinks(read(`${p}.html`)).filter((h) => h !== '/' || true);
    const bar = read(`${p}.html`).match(/<nav[^>]*>[\s\S]*?<\/nav>/)[0];
    const hrefs = [...bar.matchAll(/href="([^"]+)"/g)].map((m) => m[1]);
    assert.deepEqual(hrefs.slice(0, 2), ['/home.html', '/'], p);
    assert.ok(links.length >= 7, p);
  }
});

test('exactly one page marks itself current in the nav, and the markup agrees with the page', () => {
  assert.match(read('home.html'), /<a href="\/home\.html" aria-current="page">Home<\/a>/);
  assert.match(read('index.html'), /<a href="\/" aria-current="page">Chart read<\/a>/);
  for (const p of ['backtest', 'history', 'journal', 'options', 'strategy-builder']) {
    assert.equal((read(`${p}.html`).match(/aria-current="page"/g) || []).length, 1, p);
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
