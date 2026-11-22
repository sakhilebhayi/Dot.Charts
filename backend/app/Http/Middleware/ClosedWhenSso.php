<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Local registration and password login (POST /api/register, /api/login) close while DOTSSO_ENABLED is true, unless the
 * administrator's break-glass DOTSSO_ALLOW_LOCAL_LOGIN is set. Every other route is untouched.
 */
class ClosedWhenSso
{
    public function handle(Request $request, Closure $next)
    {
        if (config('dotsso.enabled') && ! config('dotsso.allow_local_login')) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in and registration are handled by InfoDot.',
                'sso_url' => url('/sso/login'),
            ], 403);
        }

        return $next($request);
    }
}
