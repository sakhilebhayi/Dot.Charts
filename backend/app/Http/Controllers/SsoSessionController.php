<?php

namespace App\Http\Controllers;

use App\Sso\ChartsProvisioner;
use Dot\SsoClient\Http\Controllers\SsoController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** The two small answers the static frontend needs from the session design. */
class SsoSessionController extends Controller
{
    /** Public. Tells the sign-in pages whether to hand over to InfoDot or show the legacy form. */
    public function config(): JsonResponse
    {
        $sso = (bool) config('dotsso.enabled');
        $local = ! $sso || (bool) config('dotsso.allow_local_login');

        return response()->json([
            'sso' => $sso,
            'local_login' => $local,
            'login_url' => $sso ? url('/sso/login') : null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Ends the session here and returns the InfoDot end-session address (RP-initiated logout) for the browser to open,
     * which signs the person out of every Dot platform by back-channel. A fetch cannot follow a cross-origin redirect
     * meaningfully, so the address travels as JSON and the SPA navigates to it. Same-origin and CSRF-protected.
     */
    public function logout(Request $request): JsonResponse
    {
        if (! config('dotsso.enabled') || ! $request->hasSession()) {
            return response()->json(['redirect' => url('/')]);
        }

        $redirect = app(SsoController::class)->logout($request);
        Cookie::queue(Cookie::forget(ChartsProvisioner::HINT_COOKIE));

        return response()->json(['redirect' => $redirect->getTargetUrl()])->header('Cache-Control', 'no-store');
    }
}
