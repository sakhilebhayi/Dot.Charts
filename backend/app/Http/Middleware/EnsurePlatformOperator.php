<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('sanctum')?->is_platform_operator) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
