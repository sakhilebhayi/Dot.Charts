// The Home dashboard's equity curve: one series, drawn as inline SVG from the run's own stored points
// (results.equity_curve = [{time, equity}]). Nothing is smoothed, sampled or invented. One line, a hairline grid, the
// start level labelled, a direct end label, a hover/touch/arrow-key reader, and a one-time draw-in that
// is a transform on a clip rectangle (no stroke animation), skipped under prefers-reduced-motion.
const NS = 'http://www.w3.org/2000/svg';
const STEPS = [100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000, 20000, 25000, 50000, 100000];

function mk(name, attrs) {
  const node = document.createElementNS(NS, name);
  Object.entries(attrs || {}).forEach(([k, v]) => node.setAttribute(k, v));
  return node;
}
function text(x, y, anchor, value, cls) {
  const t = mk('text', { x, y });
  if (anchor) t.setAttribute('text-anchor', anchor);
  if (cls) t.setAttribute('class', cls);
  t.textContent = value;
  return t;
}
export const money = (n) => n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const day = (v) => {
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};
const mon = (v) => {
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
};
const signed = (n) => `${n > 0 ? '+' : ''}${n.toFixed(2)}`;

let serial = 0;

/**
 * Draws the curve into `host` (which must have a height). `points` has at least two entries.
 * `summary` is the spoken description of the chart's subject, e.g. "AAPL".
 */
export function drawEquityChart(host, points, summary, { animate = false } = {}) {
  host.replaceChildren();
  const W = host.clientWidth || 520;
  const H = host.clientHeight || 200;
  const L = 52, R = 56, T = 10, B = 24;

  const times = points.map((p) => Date.parse(p.time));
  const t0 = times[0];
  const t1 = times[times.length - 1];
  const ys = points.map((p) => p.equity);
  const start = ys[0];
  let lo = Math.min(...ys);
  let hi = Math.max(...ys);
  const span = hi - lo || 1;
  const step = STEPS.find((s) => span / s <= 4) || 100000;
  lo = Math.floor((lo - span * 0.04) / step) * step;
  hi = Math.ceil((hi + span * 0.04) / step) * step;
  const X = (t) => L + ((t - t0) / (t1 - t0 || 1)) * (W - L - R);
  const Y = (v) => T + ((hi - v) / (hi - lo || 1)) * (H - T - B);

  const svg = mk('svg', {
    viewBox: `0 0 ${W} ${H}`, role: 'img', tabindex: '0',
    'aria-label': `Equity curve for ${summary}, from ${money(start)} to ${money(ys[ys.length - 1])}. Use the left and right arrow keys to read values, or open the table.`,
  });

  for (let v = lo; v <= hi; v += step) {
    svg.append(mk('line', { x1: L, x2: W - R, y1: Y(v), y2: Y(v), class: 'grid' }), text(L - 8, Y(v) + 4, 'end', v.toLocaleString('en-GB')));
  }
  svg.append(mk('line', { x1: L, x2: W - R, y1: Y(start), y2: Y(start), class: 'base' }), text(W - R + 8, Y(start) + 4, null, 'start'));
  svg.append(text(L, H - 4, null, mon(points[0].time)), text(W - R, H - 4, 'end', mon(points[points.length - 1].time)));

  const clipId = `plotclip${(serial += 1)}`;
  const line = mk('polyline', {
    class: 'ln',
    points: points.map((p, i) => `${X(times[i]).toFixed(1)},${Y(p.equity).toFixed(1)}`).join(' '),
  });
  if (animate) {
    const clip = mk('clipPath', { id: clipId });
    clip.append(mk('rect', { class: 'clip-rect', x: L - 4, y: 0, width: W - L - R + 8, height: H }));
    svg.append(clip);
    line.setAttribute('clip-path', `url(#${clipId})`);
  }
  svg.append(line);

  const last = points[points.length - 1];
  svg.append(mk('circle', { class: animate ? 'dot end' : 'dot', cx: X(t1), cy: Y(last.equity), r: 5 }));
  svg.append(text(W - R + 8, Y(last.equity) + (Y(last.equity) > Y(start) ? 14 : -6), null, money(last.equity).split('.')[0], 'end'));

  const xh = mk('line', { class: 'xh', y1: T, y2: H - B, visibility: 'hidden' });
  const hd = mk('circle', { class: 'dot', r: 5, visibility: 'hidden' });
  svg.append(xh, hd);

  const tip = document.createElement('div');
  tip.className = 'tip';
  tip.hidden = true;
  host.append(svg, tip);

  let idx = points.length - 1;
  function show(i) {
    idx = Math.max(0, Math.min(points.length - 1, i));
    const p = points[idx];
    const x = X(times[idx]);
    const y = Y(p.equity);
    xh.setAttribute('x1', x); xh.setAttribute('x2', x); xh.setAttribute('visibility', 'visible');
    hd.setAttribute('cx', x); hd.setAttribute('cy', y); hd.setAttribute('visibility', 'visible');
    const head = document.createElement('b');
    head.textContent = day(p.time);
    tip.replaceChildren(head, document.createTextNode(`${money(p.equity)}  ${signed((p.equity / start - 1) * 100)}% vs start`));
    tip.hidden = false;
    const w = tip.offsetWidth;
    tip.style.setProperty('left', `${Math.min(Math.max(x - w / 2, 0), W - w)}px`);
    tip.style.setProperty('top', `${Math.max(y - 58, 0)}px`);
  }
  function hide() {
    xh.setAttribute('visibility', 'hidden');
    hd.setAttribute('visibility', 'hidden');
    tip.hidden = true;
  }
  svg.addEventListener('pointermove', (e) => {
    const b = svg.getBoundingClientRect();
    const t = t0 + ((((e.clientX - b.left) * W) / b.width - L) / (W - L - R)) * (t1 - t0);
    let best = 0;
    let bd = Infinity;
    times.forEach((tm, i) => { const d = Math.abs(tm - t); if (d < bd) { bd = d; best = i; } });
    show(best);
  });
  svg.addEventListener('pointerleave', hide);
  svg.addEventListener('blur', hide);
  svg.addEventListener('keydown', (e) => {
    const move = { ArrowLeft: () => show(idx - 1), ArrowRight: () => show(idx + 1), Home: () => show(0), End: () => show(points.length - 1), Escape: hide }[e.key];
    if (move) { e.preventDefault(); move(); }
  });
}
