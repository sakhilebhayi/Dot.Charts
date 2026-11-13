// The Dot platform registry, mirrored from backend/config/ecosystem.php
// (itself a copy of InfoDot's shared source-of-truth). This copy is
// deliberately static rather than fetched from the API: the strip renders
// on failure surfaces, and the moment we need it most is exactly when the
// API may be unreachable. Keep the two lists in sync when the registry
// changes.
const PLATFORMS = [
  { name: 'InfoDot', url: 'https://infodot.co.za', accent: '#2f8fd6' },
  { name: 'Dot.Agents', url: 'https://agents.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.Analytics', url: 'https://analytics.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.Auction', url: 'https://auction.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.Central', url: 'https://central.infodot.co.za', accent: '#e8bd3d' },
  { name: 'Dot.Design', url: 'https://design.infodot.co.za', accent: '#efb93a' },
  { name: 'Dot.Dopemine', url: 'https://dopemine.infodot.co.za', accent: '#e8b923' },
  { name: 'Dot.Ehail', url: 'https://ehail.infodot.co.za', accent: '#e2b13d' },
  { name: 'Dot.Emall', url: 'https://emall.infodot.co.za', accent: '#eec13f' },
  { name: 'Dot.Engage', url: 'https://engage.infodot.co.za', accent: '#e8bb2c' },
  { name: 'Dot.Files', url: 'https://files.infodot.co.za', accent: '#d9a018' },
  { name: 'Dot.Finance', url: 'https://finance.infodot.co.za', accent: '#e8af39' },
  { name: 'Dot.Forms', url: 'https://forms.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.HR', url: 'https://hr.infodot.co.za', accent: '#f0b91c' },
  { name: 'Dot.Memory', url: 'https://memory.infodot.co.za', accent: '#f4c94c' },
  { name: 'Dot.Mines', url: 'https://mines.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.Notify', url: 'https://notify.infodot.co.za', accent: '#f0c33a' },
  { name: 'Dot.Press', url: 'https://pres.infodot.co.za', accent: '#f0c33a' },
  { name: 'Dot.Projects', url: 'https://projects.infodot.co.za', accent: '#f0c33a' },
  { name: 'Dot.Pulse', url: 'https://pulse.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.Sheet', url: 'https://sheets.infodot.co.za', accent: '#e3ab1f' },
  { name: 'Dot.Tasks', url: 'https://tasks.infodot.co.za', accent: '#f2a803' },
  { name: 'Dot.Tutor', url: 'https://tutor.infodot.co.za', accent: '#f1c62e' },
  { name: 'Dot.docs', url: 'https://doc.infodot.co.za', accent: '#f1c62e' },
];

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
  label.textContent = `While this recovers — the rest of the Dot Ecosystem (${PLATFORMS.length})`;
  label.style.cssText = 'font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:var(--text-muted, #94a3b8);margin:0 0 12px;text-align:center;font-family:ui-monospace,monospace';
  wrap.appendChild(label);

  const strip = document.createElement('div');
  strip.style.cssText = 'display:flex;gap:8px;overflow-x:auto;padding:2px 2px 8px;-webkit-overflow-scrolling:touch';
  PLATFORMS.forEach((p) => {
    const pill = document.createElement('a');
    pill.href = p.url;
    pill.style.cssText = 'flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:6px 12px 6px 6px;background:var(--bg-secondary, #0f172a);border:1px solid var(--border, rgba(148,163,184,0.15));border-radius:999px;text-decoration:none;white-space:nowrap';

    const dot = document.createElement('span');
    dot.setAttribute('aria-hidden', 'true');
    dot.style.cssText = `display:inline-block;width:10px;height:10px;border-radius:50%;background:${p.accent};flex-shrink:0`;
    pill.appendChild(dot);

    const name = document.createElement('span');
    name.textContent = p.name;
    name.style.cssText = 'font-weight:600;font-size:13px;color:var(--text, #e5e7eb)';
    pill.appendChild(name);

    strip.appendChild(pill);
  });
  wrap.appendChild(strip);

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
