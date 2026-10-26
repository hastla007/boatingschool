<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    private const CACHE_KEY = 'trusted-hosts:custom-domains';

    private const CACHE_TTL_MINUTES = 5;

    /**
     * Bug-Review NEU-6 (Performance und Ausfallsicherheit): als globale
     * Middleware lief diese Abfrage bisher bei JEDEM Request gegen die
     * Owner-Verbindung -- auch für /up (Health-Check) und jeden statischen
     * Asset-Request, der überhaupt durch den vollen HTTP-Stack läuft.
     * Fällt diese Verbindung aus, beantwortete bisher ALLES (nicht nur
     * Custom-Domain-Requests) mit 500, einschließlich des Health-Checks,
     * der genau diesen Ausfall eigentlich melden sollte.
     *
     * Der 'file'-Cache-Store ist hier bewusst explizit gewählt statt des
     * konfigurierten Standard-Stores (CACHE_STORE=database, siehe .env) --
     * ein DB-gestützter Cache wäre bei einem echten Postgres-Ausfall
     * genauso betroffen wie die eigentliche Abfrage selbst und würde
     * nichts entschärfen. Mit dem Dateisystem-Cache übersteht ein bereits
     * befüllter Eintrag einen Postgres-Ausfall vollständig; schlägt die
     * Abfrage fehl (Verbindungsfehler ODER kalter Cache), fällt diese
     * Methode auf die letzte bekannte (ggf. abgelaufene) Dateisystem-
     * Cache-Kopie zurück, im schlimmsten Fall auf eine leere Liste -- die
     * zentrale Domain und ihre Subdomains bleiben davon unberührt (siehe
     * patterns() oben, kommt rein aus der Config), nur noch nicht
     * gecachte/neue Custom Domains würden während eines solchen Ausfalls
     * vorübergehend als nicht vertrauenswürdig abgelehnt, statt die
     * gesamte Plattform für jeden Host mit 500 zu beantworten.
     *
     * @return array<int, string>
     */
    private static function customDomains(): array
    {
        $cache = Cache::store('file');

        try {
            return $cache->remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
                // Läuft als globale Middleware VOR jeder Tenant-/RLS-
                // Auflösung -- dieselbe Owner-Verbindung wie
                // ResolveTenant::resolveViaCustomDomain() (Sicherheitsreview
                // N9), aus demselben Grund: die App-Rolle sähe ohne
                // Tenant-Kontext ohnehin nie eine Zeile.
                return DB::connection('pgsql_admin')->table('tenant_branding')
                    ->whereNotNull('custom_domain')
                    ->pluck('custom_domain')
                    ->all();
            });
        } catch (Throwable $e) {
            report($e);

            return $cache->get(self::CACHE_KEY, []);
        }
    }
}
