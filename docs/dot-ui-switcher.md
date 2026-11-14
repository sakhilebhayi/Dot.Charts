# Dot Apps switcher (dot-ui) in Dot.Charts

The shared platform switcher sits in the app bar of every page that has one (home, console, Backtest, Strategy Builder,
History, Journal, Options Vol). Login and Register have no app bar and no switcher.

## What it says, and why

Dot.Charts has its own sign-in (Sanctum token) and no InfoDot company or service secret, so it cannot ask InfoDot
`/api/ecosystem/companies/{uuid}/platforms`, and it must not guess. The switcher therefore runs in its honest
"own sign-in" mode: the core set (InfoDot, Files, docs, Tasks, Finance) as tiles that open through InfoDot's
`/go/<key>` (InfoDot decides and signs the person in), "Dot.Charts - You are here", a visible "Could not check your
platforms from here" line with the reason, and a "See all platforms" link to InfoDot's Platforms page. Nothing says a
platform is active or held.

## Where the list comes from

`backend/config/ecosystem.php` -> `GET /api/ecosystem/switcher` (`EcosystemSwitcherController`, public, cached 5 min) ->
`frontend/src/registry.js` (one fetch per page) -> the header switcher and the failure strip (`ecosystem.js`). No list
lives in JavaScript. If the endpoint is down the header control stays a plain link to InfoDot's Platforms page and the
failure strip offers the same link. The control is created by script, so with JavaScript off there is no switcher (the
pages need JavaScript anyway). Until the script runs, the control is the same plain link to InfoDot's Platforms page.

## Files

- `frontend/src/dot-switcher-mount.js`: the one module every page script imports (like `home-link.js`).
- `frontend/src/dot-switcher-skin.css`: maps instrument tokens onto `--dsw-*`, places the control, phone layout.
- `frontend/src/dot-ui/` and `frontend/public/dot-ui/img/`: unchanged copies from dot-ui, pinned in
  `frontend/src/dot-ui/MANIFEST.json` and checked by `npm test`. Edit in dot-ui, re-copy, regenerate the manifest.
- Tests: `backend/tests/Feature/EcosystemSwitcherEndpointTest.php`, `frontend/tests/switcher.test.mjs`.

CSP: no inline style or script is added; fonts, logos and the model request are same-origin, so the report-only policy in
`security-headers.htaccess` and `SecurityHeaders.php` needs no change.
