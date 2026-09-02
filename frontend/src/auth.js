import { API_BASE } from './api-base.js';

const STORAGE_KEY = 'chartsense_token';

export function getToken() {
  return localStorage.getItem(STORAGE_KEY);
}

export function setToken(token) {
  localStorage.setItem(STORAGE_KEY, token);
}

export function clearToken() {
  localStorage.removeItem(STORAGE_KEY);
}

export function isLoggedIn() {
  return getToken() !== null;
}

// Logs out for real: revokes the Sanctum token server-side, then clears the
// local copy. Clearing localStorage alone leaves a live token behind on the
// server forever. Best-effort on the network call — a dead backend must
// never trap the user in a logged-in UI.
export async function logout() {
  const token = getToken();
  if (token) {
    try {
      await fetch(`${API_BASE}/logout`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
    } catch {
      // Unreachable backend: the local session still ends.
    }
  }
  clearToken();
}
