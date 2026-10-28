<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;

/**
 * Bug-Review T1: Illuminate\Http\Middleware\TrustProxies::at() ist ein
 * boot-zeitiger Setter einer STATISCHEN Eigenschaft -- er muss aufgerufen
 * werden, bevor die Middleware zum ersten Mal läuft. Der bisherige Aufruf
 * $middleware->trustProxies(at: ...) in bootstrap/app.php geschieht aber
 * über den withMiddleware()-Callback, den ApplicationBuilder per
 * afterResolving(HttpKernel::class, ...) registriert -- diese Auflösung
 * passiert für JEDEN frischen PHP-Prozess (jeder echte php-fpm-Request via
 * public/index.php, nicht nur Artisan-Befehle) VOR LoadConfiguration, die
 * 'config' erst in den Container bindet. app()->bound('config') ist zu
 * diesem Zeitpunkt also auch bei einem echten HTTP-Request false, der
 * Leerstring-Default greift, und TRUSTED_PROXIES aus der .env erreicht
 * at() nie -- exakt das vom Review gemeldete Verhalten ("wirkt bei echten
 * Requests nicht"). Ein in derselben PHP-CLI-Lifetime bereits einmal
 * gebootetes Testing-Setup (z. B. PHPUnit) kann das verschleiern, weil
 * 'config' dort durch einen vorherigen Bootstrap-Durchlauf schon gebunden
 * sein kann -- ein frischer php-fpm-Worker hat dieses Glück nie.
 *
 * proxies() wird dagegen nicht beim Boot, sondern bei JEDEM Request aus
 * handle() heraus aufgerufen (Illuminate\Http\Middleware\TrustProxies::
 * setTrustedProxyIpAddresses()) -- zu diesem Zeitpunkt ist LoadConfiguration
 * garantiert bereits durchlaufen. Die Überschreibung liest TRUSTED_PROXIES
 * deshalb hier, pro Request frisch aus config(), statt sich auf den
 * boot-zeitigen at()-Mechanismus zu verlassen.
 */
class TrustProxies extends BaseTrustProxies
{
    protected function proxies()
    {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('app.trusted_proxies', ''))
        )));

        return $trustedProxies !== [] ? $trustedProxies : null;
    }
}
