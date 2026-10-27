<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Support\TrustedHosts;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R3-2/R4-A: ohne trustHosts() vertraut Laravel jedem
 * Host-Header blind -- ein gefälschter Host (z. B. "...on-forge.com")
 * hätte sowohl per route()/url() erzeugte Links (Host-Header-Poisoning,
 * siehe Passwort-Reset-Mail) als auch TrustProxies' automatisches
 * "vertraue jedem Proxy" für Forge/Vapor-Hosts auslösen können, was die
 * IP-basierten throttle-Limits aushebeln würde. TrustHosts selbst läuft
 * in Laravels globaler Middleware-Liste VOR TrustProxies und schließt
 * damit beide Lücken -- getestet wird hier die Muster-Erzeugung
 * (App\Support\TrustedHosts::patterns()) direkt, weil Laravel das
 * eigentliche Durchsetzen (TrustHosts::handle()) in der Testumgebung
 * bewusst überspringt (shouldSpecifyTrustedHosts(): nie in "testing").
 */
class TrustedHostsTest extends TestCase
{
    use InteractsWithTenants;

    /**
     * Bug-Review NEU-6: TrustedHosts::customDomains() cacht das Ergebnis
     * jetzt (siehe dort) -- ohne diesen Reset vor jedem Test würde ein
     * früher im Lauf gecachtes (z. B. noch leeres) Ergebnis andere, SPÄTER
     * in diesem Lauf neu gesetzte Custom Domains verdecken und Tests von
     * ihrer Ausführungsreihenfolge abhängig machen.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget('trusted-hosts:custom-domains:data');
        Cache::store('file')->forget('trusted-hosts:custom-domains:fresh-until');
    }

    public function test_patterns_match_the_central_domain_and_its_subdomains(): void
    {
        $central = config('app.central_domain');
        $patterns = TrustedHosts::patterns();

        $this->assertTrue($this->matchesAny($patterns, $central), "Zentrale Domain \"{$central}\" wird von keinem Muster abgedeckt.");
        $this->assertTrue($this->matchesAny($patterns, 'meine-schule.'.$central), 'Eine reguläre Mandanten-Subdomain wird von keinem Muster abgedeckt.');
    }

    public function test_patterns_match_a_tenants_configured_custom_domain(): void
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'kurse.meine-bootsschule-e2e.test']));

        $patterns = TrustedHosts::patterns();

        $this->assertTrue($this->matchesAny($patterns, 'kurse.meine-bootsschule-e2e.test'), 'Konfigurierte Custom-Domain wird von keinem Muster abgedeckt.');
    }

    /**
     * Bug-Review NEU-6: customDomains() cachte das Ergebnis bisher gar
     * nicht und fragte bei JEDEM Request (auch /up, dem Health-Check) die
     * Owner-Verbindung ab. Dieser Test prüft die Cache-Seite direkt: eine
     * NACH dem ersten patterns()-Aufruf gesetzte Custom Domain darf
     * innerhalb des Cache-Fensters noch nicht auftauchen -- sonst würde
     * jeder Request weiterhin ungecacht gegen die DB laufen.
     */
    public function test_patterns_are_cached_and_do_not_reflect_a_change_within_the_cache_window(): void
    {
        $tenant = $this->createTestTenant();

        TrustedHosts::patterns();

        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'kurse.cache-test-e2e.test']));

        $patterns = TrustedHosts::patterns();

        $this->assertFalse($this->matchesAny($patterns, 'kurse.cache-test-e2e.test'), 'Eine innerhalb des Cache-Fensters neu gesetzte Custom Domain darf noch nicht auftauchen -- sonst wird gar nicht gecacht.');
    }

    /**
     * Bug-Review NEU-6 (Ausfallsicherheit): fiel die Owner-Verbindung aus,
     * beantwortete bisher die GESAMTE Plattform jeden Request mit 500 --
     * nicht nur Custom-Domain-Requests, sondern auch die zentrale Domain
     * und jede reguläre Bootsschul-Subdomain, deren Muster rein aus der
     * Config (CENTRAL_DOMAIN) kommt und gar keine DB-Abfrage braucht.
     */
    public function test_patterns_still_cover_the_central_domain_when_the_admin_connection_is_unreachable(): void
    {
        $originalConfig = config('database.connections.pgsql_admin');
        config(['database.connections.pgsql_admin.port' => 1]);
        DB::purge('pgsql_admin');

        try {
            $patterns = TrustedHosts::patterns();

            $central = config('app.central_domain');
            $this->assertTrue(
                $this->matchesAny($patterns, $central),
                'Die zentrale Domain muss auch bei einem Ausfall der Owner-Verbindung erreichbar bleiben.'
            );
        } finally {
            config(['database.connections.pgsql_admin' => $originalConfig]);
            DB::purge('pgsql_admin');
        }
    }

    /**
     * Bug-Review (Niedrig, Folgefehler): ohne einen eigenen Backoff-Wert
     * fragte ein Ausfall bei JEDEM einzelnen Request erneut die DB ab,
     * sobald der Frische-Marker abgelaufen war -- der Rückfall auf die
     * zuletzt bekannten Daten griff zwar trotzdem, aber jeder Request
     * hämmerte währenddessen weiter gegen die tote Verbindung, statt sich
     * für eine kurze Zeitspanne zurückzuziehen.
     */
    public function test_a_failed_lookup_sets_a_short_backoff_marker_to_avoid_retrying_on_every_request(): void
    {
        $originalConfig = config('database.connections.pgsql_admin');
        config(['database.connections.pgsql_admin.port' => 1]);
        DB::purge('pgsql_admin');

        try {
            TrustedHosts::patterns();

            $this->assertTrue(
                Cache::store('file')->has('trusted-hosts:custom-domains:fresh-until'),
                'Ein Fehlschlag muss denselben Frische-Marker wie ein Erfolg setzen (nur kürzer) -- sonst fragt jeder weitere Request währenddessen erneut gegen die tote Verbindung ab.'
            );
        } finally {
            config(['database.connections.pgsql_admin' => $originalConfig]);
            DB::purge('pgsql_admin');
        }
    }

    /**
     * Bug-Review NEU-6 (Folgefehler, eigener Fund): eine erste Fassung
     * cachte Custom Domains direkt über Cache::remember() mit einer
     * TTL -- Illuminate\Cache\FileStore::getPayload() löscht eine
     * abgelaufene Datei aber schon beim ERSTEN get()-Aufruf (den
     * remember() selbst vor dem Callback macht), der im Kommentar
     * versprochene "Rückfall auf die letzte bekannte, ggf. abgelaufene
     * Kopie" bei einem Ausfall existierte dadurch nie -- nach Ablauf der
     * TTL lieferte ein Ausfall immer eine leere Liste. Dieser Test prüft
     * den echten Rückfall: eine zuvor erfolgreich geladene Custom Domain
     * muss auch NACH Ablauf des Cache-Fensters noch zurückkommen, wenn in
     * genau diesem Moment die Owner-Verbindung ausfällt.
     */
    public function test_a_previously_loaded_custom_domain_survives_an_outage_after_the_cache_window_expired(): void
    {
        // Setzt NUR die Daten (ohne TTL), OHNE den Frische-Marker -- exakt
        // der Zustand nach Ablauf des Cache-Fensters, deterministisch
        // nachgebildet statt auf echte 5 Minuten Wartezeit angewiesen zu
        // sein (sonst würde ein noch gültiger, "zufällig" frischer Cache-
        // Eintrag denselben Test auch mit einer kaputten Implementierung
        // bestehen lassen, siehe Kommentar oben zur ersten Fassung).
        Cache::store('file')->forever('trusted-hosts:custom-domains:data', ['kurse.stale-fallback-e2e.test']);
        Cache::store('file')->forget('trusted-hosts:custom-domains:fresh-until');

        $originalConfig = config('database.connections.pgsql_admin');
        config(['database.connections.pgsql_admin.port' => 1]);
        DB::purge('pgsql_admin');

        try {
            $patterns = TrustedHosts::patterns();

            $this->assertTrue(
                $this->matchesAny($patterns, 'kurse.stale-fallback-e2e.test'),
                'Eine zuvor erfolgreich geladene Custom Domain muss auch bei einem Ausfall nach Ablauf des Cache-Fensters noch zurückkommen (echter Rückfall, nicht nur versprochen).'
            );
        } finally {
            config(['database.connections.pgsql_admin' => $originalConfig]);
            DB::purge('pgsql_admin');
        }
    }

    /**
     * Der eigentliche Angriff: ein Host-Header wie "x.on-forge.com" löst in
     * Illuminate\Http\Middleware\TrustProxies::setTrustedProxyIpAddresses()
     * automatisch "vertraue jedem Proxy" aus -- diese Muster dürfen so
     * einen Host unter keinen Umständen durchlassen.
     */
    public function test_patterns_reject_an_arbitrary_forge_or_vapor_style_host(): void
    {
        $patterns = TrustedHosts::patterns();

        $this->assertFalse($this->matchesAny($patterns, 'beliebig.on-forge.com'));
        $this->assertFalse($this->matchesAny($patterns, 'beliebig.on-vapor.com'));
        $this->assertFalse($this->matchesAny($patterns, 'evil.test'));
    }

    /**
     * Bug-Review R3-2/R4-A (eigener Fund während der Verifikation): die
     * ursprüngliche Verdrahtung übergab TrustedHosts::patterns() als
     * Array-Callable ([TrustedHosts::class, 'patterns']) -- Illuminate\Http\
     * Middleware\TrustHosts::hosts() prüft per match(true) aber ZUERST
     * is_array($at), DANACH erst is_callable($at). Ein Array-Callable IST
     * selbst ein Array und wurde deshalb fälschlich als LITERALE
     * Host-Liste behandelt (['App\Support\TrustedHosts', 'patterns'] als
     * "Host-Muster") statt aufgerufen zu werden -- das kompilierte zu
     * einem ungültigen Regex (der Klassenname landet als Muster) und hätte
     * JEDEN Request in Produktion mit einem PHP-Warning und
     * SuspiciousOperationException/400 abgewiesen (per echtem HTTP-Request
     * über bootstrap/app.php mit APP_ENV=production verifiziert). Ein
     * echtes Closure-Objekt (bootstrap/app.php nutzt die First-Class-
     * Callable-Syntax TrustedHosts::patterns(...)) ist kein Array und
     * nimmt zuverlässig den is_callable-Zweig.
     */
    public function test_the_configured_callable_is_actually_invoked_not_treated_as_a_literal_host_list(): void
    {
        TrustHosts::flushState();
        TrustHosts::at(TrustedHosts::patterns(...), false);

        $middleware = app(TrustHosts::class);
        $hosts = (new \ReflectionMethod($middleware, 'hosts'))->invoke($middleware);

        $this->assertNotContains(TrustedHosts::class, $hosts, 'Der Klassenname selbst taucht als "Host-Muster" auf -- das Callable wurde nicht aufgerufen, sondern als literales Array behandelt.');
        $this->assertNotContains('patterns', $hosts);
        $this->assertSame(TrustedHosts::patterns(), $hosts);

        TrustHosts::flushState();
    }

    /**
     * Pinnt die Verdrahtung in bootstrap/app.php fest: ein Array-Callable
     * ([TrustedHosts::class, 'patterns']) sieht auf den ersten Blick
     * gleichwertig aus, wird von Illuminate\Http\Middleware\TrustHosts::
     * hosts() aber als LITERALE Host-Liste statt als Callable behandelt
     * (siehe Test oben) -- nur die First-Class-Callable-Syntax
     * (TrustedHosts::patterns(...), erzeugt ein echtes Closure-Objekt) ist
     * sicher.
     */
    public function test_bootstrap_wires_trust_hosts_with_a_real_closure_not_an_array_callable(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('trustHosts(at: TrustedHosts::patterns(...)', $source);
        $this->assertStringNotContainsString('trustHosts(at: [TrustedHosts::class', $source);
    }

    private function matchesAny(array $patterns, string $host): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('{'.$pattern.'}i', $host)) {
                return true;
            }
        }

        return false;
    }
}
