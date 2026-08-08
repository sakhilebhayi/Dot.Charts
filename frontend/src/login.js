import { setToken } from './auth.js';

const API_BASE = 'http://localhost:8000/api';
const errorEl = document.getElementById('error');
const button = document.getElementById('loginButton');

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
    window.location.href = '/backtest.html';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
  }
});
