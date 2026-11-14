// The Dot platform registry for the browser, fetched ONCE per page from the backend
// (GET /api/ecosystem/switcher, which reads backend/config/ecosystem.php). There is no
// copy of the list in JavaScript. Both the header switcher and the failure-page strip
// read it from here.
//
// Resolves the model, or null when the API cannot be reached. Never rejects.
import { API_BASE } from './api-base.js';

// The only literal host in the frontend: where "see all platforms" goes when the
// registry could not be loaded (the strip and the switcher's plain-link fallback).
export const INFODOT_PLATFORMS_URL = 'https://infodot.co.za/platforms';

let pending = null;

export function registry() {
  if (!pending) {
    pending = fetch(`${API_BASE}/ecosystem/switcher`, { headers: { Accept: 'application/json' }, credentials: 'omit' })
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => (body && Array.isArray(body.platforms) ? body : null))
      .catch(() => null);
  }
  return pending;
}
