<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum's stateful SPA authentication (session cookie + CSRF), but only
 *  - while DOTSSO_ENABLED is true, so with the flag off the API behaves exactly as before, and
 *  - for requests that do not carry a bearer token: a bearer call is a machine/legacy-token call, authenticated by the
 *    token alone, never by a cookie, and so never subject to cookie CSRF rules either.
 * A request is "from the frontend" only when its Referer/Origin host is in sanctum.stateful (the Charts domain).
 */
class StatefulWhenSso extends EnsureFrontendRequestsAreStateful
{
    /**
     * @param  string|null  $mode  `optional` for a public route that attaches the signed-in person when there is one (POST /backtests):
     *                             anonymous visitors, who have no session cookie, stay stateless and need no CSRF token.
     */
    public function handle($request, $next, $mode = null)
    {
        if (! config('dotsso.enabled') || $request->bearerToken()) {
            return $next($request);
        }

        if ($mode === 'optional' && ! $request->cookies->has(config('session.cookie'))) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
