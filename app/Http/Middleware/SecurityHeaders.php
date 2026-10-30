<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Defensive response headers for the API (developer guide 12.1).
 * The SPA's own headers (CSP, HSTS for the static site) are set by the web server - see deploy/nginx.conf.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');

        // API JSON may contain personal or confidential data: never store it in shared or browser caches.
        if ($response instanceof JsonResponse) {
            $h->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure() && config('ops.security.hsts', true)) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
