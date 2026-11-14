// Mounts the shared Dot "Apps" switcher (dot-ui, static renderer) into the app bar of
// every page that has one. ONE module, imported by each page script (the same way
// home-link.js is), so the eight duplicated headers stay untouched.
//
// What the switcher says here is deliberately modest. Dot.Charts has its own sign-in
// and no InfoDot company, so it cannot know which platforms the person's company holds.
// The registry comes from the backend; the tiles are the core set opened through
// InfoDot's /go (InfoDot decides and signs the person in), under a visible "could not
// check from here" line. If the registry cannot be loaded, the control stays a plain
// link to InfoDot's Platforms page.
import './dot-ui/css/dot-fonts.css';
import './dot-ui/css/dot-switcher-fonts.css';
import './dot-ui/css/dot-switcher.css';
import './dot-switcher-skin.css';
import './dot-ui/js/dot-switcher.js';
import { mount } from './dot-ui/js/dot-switcher-static.js';
import { registry, INFODOT_PLATFORMS_URL } from './registry.js';

const LOGOS = '/dot-ui/img/platforms';

function grid() {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'dsw-btn__icon');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  [4, 10, 16].forEach((y) => [4, 10, 16].forEach((x) => {
    const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    r.setAttribute('x', x); r.setAttribute('y', y); r.setAttribute('width', 4); r.setAttribute('height', 4);
    r.setAttribute('rx', 1.2); r.setAttribute('fill', 'currentColor'); r.setAttribute('stroke', 'none');
    svg.appendChild(r);
  }));
  return svg;
}

// The pre-load state: the same plain link the no-JavaScript case gets.
function placeholder() {
  const root = document.createElement('div');
  root.className = 'dsw dsw--header';
  const a = document.createElement('a');
  a.className = 'dsw-fallback';
  a.href = INFODOT_PLATFORMS_URL;
  a.title = 'Switch Dot platform';
  a.appendChild(grid());
  const label = document.createElement('span');
  label.className = 'dsw-btn__label';
  label.textContent = 'Dot Apps';
  a.appendChild(label);
  root.appendChild(a);
  return root;
}

const bars = document.querySelectorAll('header.bar');
if (bars.length) {
  const roots = [];
  bars.forEach((bar) => {
    const root = placeholder();
    bar.appendChild(root);
    roots.push(root);
  });
  registry().then((model) => {
    if (!model) return; // stays a plain link to InfoDot's Platforms page
    roots.forEach((root) => {
      // The plain link is replaced by the model's own InfoDot URL, then the panel is built.
      mount(root, model, { logos: LOGOS });
    });
  });
}
