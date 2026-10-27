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

        // Bug-Review (Hoch): Fragen-/Praxis-/Logo-Bilder werden über
        // MediaAsset::storagePath() als ABSOLUTE URL ausgeliefert, fest
        // auf den in APP_URL konfigurierten Host verankert (config/
        // filesystems.php: 'url' => env('APP_URL').'/storage'). Auf einer
        // Bootsschul-Subdomain oder Custom Domain ist das ein ANDERER
        // Origin als die aufgerufene Seite selbst -- img-src 'self' lässt
        // nur den EIGENEN Origin zu, der Browser blockierte das Bild also
        // unabhängig davon, dass der Server es korrekt auslieferte. Die
        // Testsuite lief bisher immer unter genau einem Host (demselben
        // wie APP_URL), weshalb das nie auffiel. Der aus APP_URL
        // abgeleitete Origin wird deshalb zusätzlich erlaubt, unabhängig
        // davon, von welchem Host die Seite selbst gerade aufgerufen wird.
        $appOrigin = self::appUrlOrigin();

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            // Bug-Review (Fonts von fonts.bunny.net ohne Einwilligung):
            // Figtree/Caveat werden seit resources/css/app.css (@fontsource)
            // selbst gehostet statt von fonts.bunny.net geladen -- die
            // Freigabe dieses Drittanbieters in style-src/font-src wird
            // dadurch nicht mehr benötigt.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data: https://*.tile.openstreetmap.org".($appOrigin ? " {$appOrigin}" : ''),
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

        // Bug-Review R4-J: $request->secure() spiegelt nur die
        // tatsächliche TLS-Verbindung, die PHP selbst sieht -- hinter
        // einem TLS-terminierenden Reverse-Proxy (nginx, Load Balancer,
        // CDN) kommt der Request bei PHP intern per Klartext-HTTP an, nur
        // der Proxy weiß per X-Forwarded-Proto, dass der Browser selbst
        // über HTTPS verbunden war. Dieser Header wird aber nur
        // ausgewertet, wenn die Proxy-IP in TRUSTED_PROXIES eingetragen
        // ist (bootstrap/app.php, siehe dortigen Kommentar) -- ohne das
        // bleibt $request->secure() in genau diesem, in Produktion
        // üblichen Aufbau dauerhaft false, und dieser Header würde nie
        // gesendet, obwohl die Seite für jeden Browser ausschließlich über
        // HTTPS erreichbar ist. Das ist eine Konfigurations-, keine
        // Code-Garantie (siehe .env.example) -- der Code kann ohne
        // korrekt gesetztes TRUSTED_PROXIES nicht zuverlässig wissen, ob
        // der Browser wirklich über HTTPS verbunden war.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * @return string|null Schema+Host(+Port) aus APP_URL, oder null, wenn
     *                     APP_URL nicht geparst werden kann.
     */
    private static function appUrlOrigin(): ?string
    {
        $parts = parse_url(config('app.url'));

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
