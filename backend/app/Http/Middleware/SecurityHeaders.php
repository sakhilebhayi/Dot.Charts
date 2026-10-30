<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every Laravel response (fleet baseline, R-22 Priority 5.2; same shape as InfoDot's
 * middleware). A header the route already set is left alone.
 *
 * ENFORCED now: nosniff, X-Frame-Options SAMEORIGIN, a strict-origin-when-cross-origin referrer policy, a
 * Permissions-Policy that denies the powerful features this app never uses, and HSTS on secure requests only.
 * HSTS deliberately omits includeSubDomains and preload because sibling subdomains are not all on HTTPS-only.
 *
 * The Content-Security-Policy is REPORT-ONLY: the browser reports what it would have blocked to /csp-report and
 * blocks nothing, so the policy can be tightened from real traffic before anything is enforced.
 *
 * Dot.Charts' pages are STATIC files in the web docroot, not Laravel views, so PHP never sees those requests. The
 * docroot gets the same headers from frontend/security-headers.htaccess (spliced into the docroot .htaccess by
 * .github/workflows/deploy.yml). The two must say the same thing; SecurityHeadersTest compares them. The origins
 * listed are the ones the pages really load: Google Fonts (IBM Plex on every page, Material Symbols on the error
 * pages). 'unsafe-inline' stays on script-src because index.html carries an inline onclick handler, and
 * 'unsafe-eval' is part of the fleet baseline until the report log shows it can go.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

        $hsts = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hsts > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hsts;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            // fonts.googleapis.com: IBM Plex Mono, IBM Plex Sans (Condensed) and the error pages' Material Symbols.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            // data: and blob: cover the chart screenshot preview (FileReader) on the analysis page.
            "img-src 'self' data: blob:",
            "connect-src 'self' wss: https:",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);
    }
}
