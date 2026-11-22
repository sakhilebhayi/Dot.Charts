import { setToken, authConfig, ssoStartUrl } from './auth.js';
import { API_BASE } from './api-base.js';

const errorEl = document.getElementById('error');
const button = document.getElementById('loginButton');
const handover = document.getElementById('handover');
const legacy = document.getElementById('legacyForm');
const handoverText = document.getElementById('handoverText');
const handoverLink = document.getElementById('handoverLink');

// When the platform signs people in through InfoDot, this page is only a hand-over: it sends the visitor to the backend's
// /sso/login, carrying the page they were on. Otherwise (or with the administrator's break-glass and ?local=1) the original
// password form is shown.
authConfig().then((config) => {
  const wantsLocal = config.local_login && new URLSearchParams(window.location.search).get('local') === '1';

  if (config.sso && !wantsLocal) {
    const to = ssoStartUrl('login');
    handoverLink.href = to;
    handoverText.textContent = 'Taking you to InfoDot to sign in.';
    window.location.replace(to);
  } else {
    handover.hidden = true;
    legacy.hidden = false;
  }
});

button.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  button.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.message || 'Login failed');
    }

    setToken(body.token);
    window.location.href = '/home.html';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
  }
});
