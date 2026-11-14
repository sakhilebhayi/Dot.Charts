// A way back to the signed-in landing from every page that carries the app
// bar. The markup ships the signed-out destination ("/", the public
// console); when a token is held, the logo leads to /home.html instead and
// the public console's static "Log in" link is hidden (Home is in every nav). Display only:
// this reads the sign-in state and never changes it.
import { isLoggedIn } from './auth.js';

if (isLoggedIn()) {
  document.querySelectorAll('.bar a[aria-label="Dot.Charts home"]').forEach((logo) => {
    logo.href = '/home.html';
  });
  // The nav now carries Home for everyone, so the public console's "Log in" link just steps aside.
  document.querySelectorAll('.bar nav a.login').forEach((link) => {
    link.hidden = true;
  });
}
