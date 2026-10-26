<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Bug-Review R3-2/R4-A: ohne trustHosts() vertraut Laravel jedem
 * Host-Header blind -- Request::getHost() wird u. a. für jede per
 * route()/url() erzeugte absolute URL verwendet (z. B. der Link in der
 * Passwort-Reset-Mail, siehe ResolveTenant::isTenantAgnosticRoute()). Ein
 * gefälschter Host-Header hätte diesen Link auf eine beliebige, vom
 * Angreifer kontrollierte Domain zeigen lassen können (Host-Header-
 * Poisoning). Zusätzlich löst Illuminate\Http\Middleware\TrustProxies::
 * setTrustedProxyIpAddresses() bei einem Host-Header, der auf
 * ".on-forge.com"/".on-vapor.com" endet, automatisch "vertraue JEDEM
 * Proxy" aus ($trustedIps = '*') -- unabhängig davon, ob TRUSTED_PROXIES
 * (siehe bootstrap/app.php, Bug-Review M4-Folgefehler) überhaupt gesetzt
 * ist. Ein Angreifer könnte das mit einem frei wählbaren, gefälschten
 * Host-Header erzwingen und darüber X-Forwarded-For beliebig fälschen,
 * was wiederum die IP-basierten throttle-Limits (routes/auth.php)
 * aushebeln würde. Da TrustHosts in Laravels globaler Middleware-Liste
 * VOR TrustProxies läuft, schließt ein aktiviertes trustHosts() beide
 * Lücken: ein Host-Header, der zu keinem der folgenden Muster passt, wird
 * schon hier mit einer SuspiciousOperationException/400 abgelehnt, bevor
 * TrustProxies ihn überhaupt zu Gesicht bekommt.
 *
 * Eigene Logik statt Illuminate\Http\Middleware\TrustHosts::
 * allSubdomainsOfApplicationUrl() (das auf APP_URL basiert): dieses
 * Projekt löst Mandanten über CENTRAL_DOMAIN auf (siehe ResolveTenant),
 * das von APP_URL abweichen kann -- zusätzlich müssen die je Mandant frei
 * konfigurierbaren Custom-Domains (tenant_branding.custom_domain)
 * ebenfalls vertraut werden, was ein statisches APP_URL-Muster nicht
 * leisten könnte.
 */
class TrustedHosts
{
    /** @return array<int, string> Regex-Muster ohne Delimiter, wie von Illuminate\Http\Middleware\TrustHosts erwartet. */
    public static function patterns(): array
    {
        $central = config('app.central_domain');

        $patterns = [
            '^'.preg_quote($central).'$',
            '^(.+\.)'.preg_quote($central).'$',
        ];

        foreach (self::customDomains() as $domain) {
            $patterns[] = '^'.preg_quote($domain).'$';
        }

        return $patterns;
    }

    /** @return array<int, string> */
    private static function customDomains(): array
    {
        // Läuft als globale Middleware VOR jeder Tenant-/RLS-Auflösung --
        // dieselbe Owner-Verbindung wie ResolveTenant::resolveViaCustomDomain()
        // (Sicherheitsreview N9), aus demselben Grund: die App-Rolle sähe
        // ohne Tenant-Kontext ohnehin nie eine Zeile.
        return DB::connection('pgsql_admin')->table('tenant_branding')
            ->whereNotNull('custom_domain')
            ->pluck('custom_domain')
            ->all();
    }
}
