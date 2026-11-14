// A way back to the signed-in landing from every page that carries the app
// bar. The markup ships the signed-out destination ("/", the public
// console); when a token is held, the logo leads to /home.html instead and
// the public console's static "Log in" link becomes "Home". Display only:
// this reads the sign-in state and never changes it.
import { isLoggedIn } from './auth.js';

if (isLoggedIn()) {
  document.querySelectorAll('.bar a[aria-label="Dot.Charts home"]').forEach((logo) => {
    logo.href = '/home.html';
  });
  document.querySelectorAll('.bar nav a.login').forEach((link) => {
    link.textContent = 'Home';
    link.href = '/home.html';
  });
}
