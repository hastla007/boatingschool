<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsreview M9: ohne diese Header ließen sich u. a. die
 * Superadmin-Formulare per Clickjacking in ein fremdes iframe einbetten,
 * und ein über H4 hinausgehender MIME-Sniffing-Angriff auf hochgeladene
 * Dateien war nicht zusätzlich erschwert. Die CSP erlaubt weiterhin
 * 'unsafe-eval' für script-src, weil Alpine.js (ohne die CSP-Alpine-
 * Variante) Direktiven wie x-data über new Function() auswertet -- eine
 * strengere Policy würde die gesamte UI brechen. tile.openstreetmap.org
 * ist für die Kartenkacheln der Leaflet-Karte im Praxisformular nötig
 * (siehe praxis-pruefung/index.blade.php) -- Leaflet selbst kam früher vom
 * unpkg.com-CDN und erlaubte dafür die GANZE Domain (CSP kennt nur
 * Origin-, keine Pfad-Einschränkungen), ist jetzt aber per npm/Vite
 * gebündelt (Bug-Review M9, Folgefehler), unpkg.com ist deshalb nirgends
 * mehr nötig.
 *
 * 'unsafe-inline' für script-src ist entfernt: die wenigen eigenen
 * <script>-Blöcke bekommen stattdessen ein pro Request erzeugtes Nonce
 * (siehe $cspNonce/x-script-nonce-Blade-Component), alle onclick=/
 * onsubmit=/onchange=-Attribute wurden zu Alpine-Direktiven (@click/
 *
 * @submit/@change). 'unsafe-inline' bleibt für style-src bewusst
 * bestehen: die tenant-abhängige Branding-Farbe (var(--brand-primary, ...))
 * steckt als inline style=-Attribut in >70 Templates -- CSP-Nonces greifen
 * nur bei <style>-Blöcken, nicht beim style=-Attribut selbst, und
 * Hash-basierte CSP wäre bei so vielen, sich häufig ändernden Werten zu
 * fragil (ein editierter style-String ohne aktualisierten Hash würde die
 * Seite lautlos kaputt machen). Das ist ein bewusst akzeptiertes Risiko,
 * kein Versehen.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        view()->share('cspNonce', $nonce);

        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net",
            "img-src 'self' data: https://*.tile.openstreetmap.org",
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
