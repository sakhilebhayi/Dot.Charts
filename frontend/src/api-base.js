// Single source of truth for the API origin.
//
// Priority: an explicit VITE_API_BASE at build time; otherwise localhost
// dev talks to the local Laravel serve, and any deployed origin talks to
// its own /api (the production layout serves the built frontend and the
// Laravel API from the same domain, so same-origin is both correct and
// CORS-free).
export const API_BASE =
  import.meta.env.VITE_API_BASE ??
  (['localhost', '127.0.0.1'].includes(window.location.hostname)
    ? 'http://localhost:8000/api'
    : '/api');
