<?php

namespace App\Http\Middleware;

use Closure;
use Dot\SsoClient\Support\DeepLink;
use Illuminate\Http\Request;

/**
 * The SPA sends people to GET /sso/login?next=/journal.html. The package keeps the deep link only in its server-side state
 * record, taken from the session's `url.intended`, so this middleware (web group, after the session starts) validates `next`
 * with the package's own DeepLink rules (same-origin in-app page, allowlist, denylist) and stores THAT in the session.
 * Anything invalid clears any stale value, so the person lands on the landing page (/dashboard, which redirects to /home.html).
 */
class CarryDeepLink
{
    public function handle(Request $request, Closure $next)
    {
        if (config('dotsso.enabled') && $request->isMethod('GET') && $request->is('sso/login') && $request->hasSession() && $request->query->has('next')) {
            $next_ = $request->query('next');
            $clean = is_string($next_) ? DeepLink::clean($next_, $request) : null;

            if ($clean !== null && ! $this->isChartsPage($clean)) {
                $clean = null;
            }

            if ($clean !== null) {
                $request->session()->put('url.intended', $clean);
            } else {
                // A `next` that is not an in-app page is dropped, and so is any older value: the person lands on the landing page.
                $request->session()->forget('url.intended');
            }
        }

        return $next($request);
    }

    private function isChartsPage(string $path): bool
    {
        foreach ((array) config('dotsso.charts_pages', []) as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
