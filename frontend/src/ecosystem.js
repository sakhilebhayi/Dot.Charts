// The "while this recovers, the rest of the Dot Ecosystem" strip for failure surfaces.
// The platform list is NOT kept here: it comes from the backend registry through
// registry.js (one fetch per page). If the API is the thing that is down, the strip
// says so and offers the one stable link, InfoDot's Platforms page.
import { registry, INFODOT_PLATFORMS_URL } from './registry.js';

const INTERNAL = ['memory', 'dopemine', 'brain', 'assets'];

// True for failures where the feature itself is down (network unreachable,
// or a 5xx from our side) — the cases where the Dot.Mines-style "while
// you're here, the rest of the ecosystem" strip belongs. Validation
// errors (4xx) are the user's to fix and never trigger marketing.
export function isHardFailure(status) {
  return status === null || status === undefined || status >= 500;
}

export function ecosystemStrip() {
  const wrap = document.createElement('div');
  wrap.style.cssText = 'margin-top:18px;padding-top:16px;border-top:1px solid var(--border, rgba(148,163,184,0.15));text-align:left';

  const label = document.createElement('p');
  label.textContent = 'While this recovers — the rest of the Dot Ecosystem';
  label.style.cssText = 'font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:var(--text-muted, #94a3b8);margin:0 0 12px;text-align:center;font-family:ui-monospace,monospace';
  wrap.appendChild(label);

  const strip = document.createElement('div');
  strip.style.cssText = 'display:flex;gap:8px;overflow-x:auto;padding:2px 2px 8px;-webkit-overflow-scrolling:touch';
  wrap.appendChild(strip);

  const pillCss = 'flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:6px 12px 6px 6px;background:var(--bg-secondary, #0f172a);border:1px solid var(--border, rgba(148,163,184,0.15));border-radius:999px;text-decoration:none;white-space:nowrap';

  const pill = (href, text, accent) => {
    const a = document.createElement('a');
    a.href = href;
    a.style.cssText = pillCss;
    if (accent) {
      const dot = document.createElement('span');
      dot.setAttribute('aria-hidden', 'true');
      dot.style.cssText = `display:inline-block;width:10px;height:10px;border-radius:50%;background:${accent};flex-shrink:0`;
      a.appendChild(dot);
    }
    const name = document.createElement('span');
    name.textContent = text;
    name.style.cssText = 'font-weight:600;font-size:13px;color:var(--text, #e5e7eb)';
    a.appendChild(name);
    return a;
  };

  strip.appendChild(pill(INFODOT_PLATFORMS_URL, 'See all platforms at InfoDot'));

  registry().then((model) => {
    if (!model) return;
    const list = model.platforms.filter((p) => p.key !== model.current && INTERNAL.indexOf(p.key) === -1 && /^https:\/\//.test(p.url));
    if (!list.length) return;
    strip.textContent = '';
    label.textContent = `While this recovers — the rest of the Dot Ecosystem (${list.length})`;
    list.forEach((p) => strip.appendChild(pill(p.url, p.name, /^#[0-9a-f]{3,8}$/i.test(p.accent) ? p.accent : '')));
  });

  return wrap;
}

// One-call failure renderer for pages whose error surface is a simple
// message element: shows the message, and appends the ecosystem strip
// only when the feature itself is down (never for validation errors).
export function showFailure(errorEl, message, status) {
  errorEl.textContent = message; // also wipes any strip from a prior failure
  errorEl.style.display = 'block';
  if (isHardFailure(status)) {
    errorEl.appendChild(ecosystemStrip());
  }
}
