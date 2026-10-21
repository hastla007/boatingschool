<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsreview M9: ohne diese Header ließen sich u. a. die
 * Superadmin-Formulare per Clickjacking in ein fremdes iframe einbetten,
 * und ein über H4 hinausgehender MIME-Sniffing-Angriff auf hochgeladene
 * Dateien war nicht zusätzlich erschwert. Die CSP erlaubt bewusst
 * 'unsafe-eval'/'unsafe-inline' für script-src, weil Alpine.js (ohne die
 * CSP-Alpine-Variante) Direktiven wie x-data über new Function() auswertet
 * -- eine strengere Policy würde die gesamte UI brechen. unpkg.com/
 * tile.openstreetmap.org sind für die Leaflet-Karte im Praxisformular
 * nötig (siehe praxis-pruefung/index.blade.php).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://unpkg.com",
            "font-src 'self' https://fonts.bunny.net",
            "img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com",
            "connect-src 'self'",
            "media-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
