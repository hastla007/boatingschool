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

    private const DATA_KEY = 'trusted-hosts:custom-domains:data';

    private const FRESH_KEY = 'trusted-hosts:custom-domains:fresh-until';

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
     * nichts entschärfen.
     *
     * Bug-Review NEU-6 (Folgefehler, eigener Fund): eine erste Fassung
     * nutzte dafür Cache::remember() mit einer 5-minütigen TTL direkt auf
     * demselben Schlüssel -- Illuminate\Cache\FileStore::getPayload()
     * LÖSCHT eine abgelaufene Datei aber schon beim ersten get()-Aufruf
     * (den remember() selbst zuerst macht, bevor es den Callback
     * überhaupt aufruft), noch bevor ein Ausfall je zum eigenen try/catch
     * unten durchdringen konnte -- der dort versprochene "Rückfall auf
     * die letzte bekannte, ggf. abgelaufene Kopie" existierte dadurch nie:
     * nach Ablauf der TTL lieferte ein Ausfall immer eine leere Liste, per
     * Tinker verifiziert (Cache::store('file')->get() nach Ablauf liefert
     * nachweislich null, nicht den letzten Wert). Die eigentliche
     * Degradation (zentrale Domain bleibt erreichbar, nur Custom Domains
     * würden während des Ausfalls vorübergehend abgelehnt) war damit zwar
     * immer noch besser als der ungefangene 500 von vorher, aber schwächer
     * als dokumentiert.
     *
     * Echter Rückfall jetzt über zwei getrennte Schlüssel: die Daten
     * selbst (DATA_KEY) bleiben ohne TTL stehen und werden nur bei einer
     * ERFOLGREICHEN Abfrage überschrieben; ein separater, kurzlebiger
     * Marker (FRESH_KEY) entscheidet nur, ob überhaupt neu abgefragt wird.
     * Schlägt die Abfrage fehl (Verbindungsfehler), bleiben die zuletzt
     * erfolgreich geladenen Daten unter DATA_KEY unverändert stehen und
     * werden zurückgegeben -- echt, nicht nur versprochen.
     *
     * Invalidieren: ein plain `php artisan cache:clear` räumt nur den
     * KONFIGURIERTEN Standard-Store (CACHE_STORE=database) -- dieser hier
     * nutzt bewusst den davon unabhängigen 'file'-Store (s. o.) und wird
     * davon NICHT getroffen. `php artisan cache:clear --store=file` leert
     * ihn gezielt (oder einfach bis zu CACHE_TTL_MINUTES abwarten).
     *
     * @return array<int, string>
     */
    private static function customDomains(): array
    {
        $cache = Cache::store('file');

        if ($cache->has(self::FRESH_KEY)) {
            return $cache->get(self::DATA_KEY, []);
        }

        try {
            // Läuft als globale Middleware VOR jeder Tenant-/RLS-Auflösung
            // -- dieselbe Owner-Verbindung wie ResolveTenant::
            // resolveViaCustomDomain() (Sicherheitsreview N9), aus
            // demselben Grund: die App-Rolle sähe ohne Tenant-Kontext
            // ohnehin nie eine Zeile.
            $domains = DB::connection('pgsql_admin')->table('tenant_branding')
                ->whereNotNull('custom_domain')
                ->pluck('custom_domain')
                ->all();

            $cache->forever(self::DATA_KEY, $domains);
            $cache->put(self::FRESH_KEY, true, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return $domains;
        } catch (Throwable $e) {
            report($e);

            return $cache->get(self::DATA_KEY, []);
        }
    }
}
