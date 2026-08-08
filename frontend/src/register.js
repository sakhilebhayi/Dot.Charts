import { setToken } from './auth.js';

const API_BASE = 'http://localhost:8000/api';
const errorEl = document.getElementById('error');
const button = document.getElementById('registerButton');

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
    window.location.href = '/backtest.html';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
  }
});
