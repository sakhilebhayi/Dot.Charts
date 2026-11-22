<?php

/*
 * Dot.Charts' policy for the shared sign-in package (packages/dot-sso-client, config/dotsso.php there holds every other
 * key and its default). Only the keys Charts decides are set here; the package merges the rest. Everything stays off
 * until DOTSSO_ENABLED=true on the host.
 *
 * `landing` and `deep_link` are left at the package defaults on purpose (the package's own test suite runs in this repo
 * and expects them): Charts narrows deep links in App\Http\Middleware\CarryDeepLink, which is the only thing that ever
 * writes the session's `url.intended`, and the package landing path /dashboard redirects to /home.html (routes/web.php).
 */
return [
    'provisioner' => \App\Sso\ChartsProvisioner::class,

    // Charts has always let anyone make an account (registration was open), so a person InfoDot has verified and who matches
    // no existing account gets one. An existing account with the same email is NEVER linked on email alone: Charts did not
    // verify emails, so those accounts go through the emailed claim code.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', true),

    // The pages a deep link may name (the page files the frontend ships), with a plain query string. Used by CarryDeepLink.
    'charts_pages' => [
        '#^/(home|backtest|history|journal|options|strategy-builder)\.html(\?[A-Za-z0-9_=&%.\-]{0,200})?$#',
        '#^/(\?[A-Za-z0-9_=&%.\-]{0,200})?$#',
    ],

    // New API tokens expire after this many days (the old ones never did).
    'token_ttl_days' => (int) env('CHARTS_TOKEN_TTL_DAYS', 30),
];
