import { setToken, authConfig, ssoStartUrl } from './auth.js';
import { API_BASE } from './api-base.js';
const errorEl = document.getElementById('error');
const button = document.getElementById('registerButton');

const handover = document.getElementById('handover');
const legacy = document.getElementById('legacyForm');
const handoverText = document.getElementById('handoverText');
const handoverLink = document.getElementById('handoverLink');

// With InfoDot in charge, creating an account happens there (register screen), then back to the page that was asked for.
authConfig().then((config) => {
  const wantsLocal = config.local_login && new URLSearchParams(window.location.search).get('local') === '1';

  if (config.sso && !wantsLocal) {
    const to = ssoStartUrl('register');
    handoverLink.href = to;
    handoverText.textContent = 'Taking you to InfoDot to create your account.';
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
    const response = await fetch(`${API_BASE}/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        name: document.getElementById('name').value.trim(),
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      const firstError = body.errors ? Object.values(body.errors)[0][0] : body.message;
      throw new Error(firstError || 'Registration failed');
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
