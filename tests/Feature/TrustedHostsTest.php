<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Support\TrustedHosts;
use Illuminate\Http\Middleware\TrustHosts;
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
