<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTPS and browser security headers (NFR-001, NFR-005; D-183).
 *
 * When `brevon.force_https` is on (default: production), plain HTTP requests
 * are redirected to HTTPS and responses carry HSTS. Every response gets
 * headers that stop the app being framed by other sites, stop MIME
 * sniffing, limit the referrer and switch off browser features the POS
 * never uses.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $forceHttps = (bool) config('brevon.force_https');

        // The /up health check stays reachable over HTTP for load balancers.
        if ($forceHttps && ! $request->secure() && ! $request->is('up')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");

        if ($forceHttps && $request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
