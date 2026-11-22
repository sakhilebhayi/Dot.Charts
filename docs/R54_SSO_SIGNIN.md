# R-54: signing in to Dot.Charts through InfoDot

Everything here is behind `DOTSSO_ENABLED` (default `false`). With the flag off Charts behaves exactly as before.

## What changes when the flag is on

| Before | After |
|---|---|
| `login.html` / `register.html` show a password form | They hand the visitor to `/sso/login` (InfoDot), carrying the in-app page they were on as `next` |
| A never-expiring Sanctum token in `localStorage` | An HttpOnly, SameSite=Lax Laravel session cookie (Sanctum stateful SPA mode, same origin) with CSRF protection; no token is stored or put in a URL |
| `POST /api/login`, `/api/register` open | Closed with a 403 (`DOTSSO_ALLOW_LOCAL_LOGIN=true` is the break-glass) |
| Logout revokes the token | Logout ends the session, then InfoDot's end-session (signs the person out of every Dot platform); InfoDot's own logout reaches Charts by back-channel |

Kept as they were: every chart, upload, OCR, journal, analytics and manifest route, the public analysis/backtest endpoints, the
compliance disclaimers, the instrument skin, `FindUser`/`PurgeUser`, and the bearer-token API (a bearer call never uses the cookie,
so it needs no CSRF token).

## How the session works

* `StatefulWhenSso` wraps Sanctum's `EnsureFrontendRequestsAreStateful`: a request is session-authenticated only when the flag is on,
  it carries no bearer token and its Referer/Origin host is in `SANCTUM_STATEFUL_DOMAINS`.
* The SPA reads one plain cookie, `charts_signed_in`, to know a session exists (it grants nothing). Writes send the `XSRF-TOKEN`
  cookie back as `X-XSRF-TOKEN`; on a 419 the SPA refreshes `/sanctum/csrf-cookie` and retries once.
* `POST /api/backtests` is public but attaches the signed-in person: it is stateful only when a session cookie is present.
* The deep link: the SPA sends `/sso/login?next=/journal.html`. `CarryDeepLink` validates it with the package's `DeepLink` rules and a
  Charts page allowlist and stores it server-side; the package keeps it in the state record and validates it again at the end.

## Matching a person (the account-takeover rule)

1. the Charts account that already stores their InfoDot id;
2. an account with the same email, linked only if InfoDot says the email is verified AND the Charts account is email-verified
   (Charts never verified emails, so in practice no existing account is);
3. otherwise the claim flow: a 6-digit code is emailed to the address on file; only the right code links the account. Its password,
   charts, backtests, strategies and journal are untouched;
4. otherwise a new account is created (`auto_provision`, because registration was open before).

Linking deletes the account's old personal access tokens (the never-expiring ones in browsers' `localStorage`). New tokens expire
after `CHARTS_TOKEN_TTL_DAYS` (30). `php artisan tokens:revoke-legacy [--force]` deletes every token that has no expiry, when the
owner decides the legacy bearer API ends.

## Host steps (owner; never run by the build)

1. Merge, then run the manual deploy workflow (migrations add `users.infodot_user_uuid`, `dotsso_revocations`, `dotsso_claims`).
2. InfoDot host: `php artisan sso:platform register charts --name="Dot.Charts" --domain=https://charts.infodot.co.za --redirect=https://charts.infodot.co.za/sso/callback --post-logout=https://charts.infodot.co.za/ --backchannel=https://charts.infodot.co.za/sso/backchannel-logout` (the secret is printed once).
3. Charts `backend/.env`: `DOTSSO_ISSUER=https://infodot.co.za`, `DOTSSO_CLIENT_ID`, `DOTSSO_CLIENT_SECRET`, `SESSION_DRIVER=database`,
   `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=charts.infodot.co.za`, working `MAIL_*` (the claim code is emailed), then
   `DOTSSO_ENABLED=true`.
4. Check: sign in from a deep page, an existing account's claim, logout, one bearer call. Rollback: `DOTSSO_ENABLED=false`.
   Emergency local login: `DOTSSO_ALLOW_LOCAL_LOGIN=true`.
