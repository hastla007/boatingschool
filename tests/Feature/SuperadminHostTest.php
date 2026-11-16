<?php

namespace Tests\Feature;

use App\Support\CanonicalUrl;
use App\Support\TrustedHosts;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Härtung SEC-W-4/SEC-T3: optionaler eigener Host für den Superadmin-Bereich
 * (SUPERADMIN_HOST). Im Pfad-Modus teilen sich sonst alle Schulen und der
 * Superadmin einen Ursprung.
 */
class SuperadminHostTest extends TestCase
{
    use InteractsWithTenants;

    private string $central;

    private string $adminHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = (string) config('app.central_domain');
        $this->adminHost = 'admin-host.'.$this->central;
    }

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_without_superadmin_host_the_area_stays_on_the_central_domain(): void
    {
        config(['app.superadmin_host' => null]);
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get("http://{$this->central}/superadmin")->assertOk();
        $this->actingAs($superadmin)->get("http://{$this->adminHost}/superadmin")->assertNotFound();
    }

    public function test_with_superadmin_host_the_area_is_only_reachable_there(): void
    {
        config(['app.superadmin_host' => $this->adminHost]);
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        // Auf dem Superadmin-Host: Anmeldung und Rechtstexte ja, / führt zum Superadmin.
        $this->get("http://{$this->adminHost}/login")->assertOk();
        $this->get("http://{$this->adminHost}/")->assertRedirect('/superadmin');
        $this->get("http://{$this->adminHost}/impressum")->assertOk();

        $this->actingAs($superadmin)->get("http://{$this->adminHost}/superadmin")->assertOk()->assertSee('Superadmin-Dashboard');
        // Zentrale Domain (auch im Schul-Pfad) und Schul-Subdomains: 404.
        $this->actingAs($superadmin)->get("http://{$this->central}/superadmin")->assertNotFound();
        $this->actingAs($superadmin)->get("http://{$this->central}/".strtolower($tenant->slug).'/superadmin')->assertNotFound();
        $this->actingAs($superadmin)->get('http://'.strtolower($tenant->slug).".{$this->central}/superadmin")->assertNotFound();

        // Schulseiten gibt es dort nicht.
        $this->actingAs($superadmin)->get("http://{$this->adminHost}/dashboard")->assertNotFound();

        // Host wird vertraut (TrustHosts) und bekommt ein Zertifikat (Caddy on-demand TLS).
        $this->assertContains('^'.preg_quote($this->adminHost).'$', TrustedHosts::patterns());
        $this->get('http://127.0.0.1/tls-check?domain='.$this->adminHost)->assertOk();
        // Rückweg aus der Support-Ansicht („Als Nutzer ansehen“) führt dorthin.
        $this->assertSame("http://{$this->adminHost}", CanonicalUrl::superadminRoot());
    }

    public function test_a_school_slug_matching_the_superadmin_host_never_resolves_there(): void
    {
        $tenant = $this->createTestTenant();
        $slug = strtolower($tenant->slug);
        config(['app.superadmin_host' => "{$slug}.{$this->central}", 'app.tenant_url_mode' => 'subdomain']);

        $this->get("http://{$slug}.{$this->central}/dashboard")->assertNotFound();
    }

    public function test_login_elsewhere_sends_the_superadmin_to_the_superadmin_host(): void
    {
        config(['app.superadmin_host' => $this->adminHost]);
        $this->createSuperAdmin(['email' => 'super-host-login@platform.test']);

        $this->post("http://{$this->central}/login", ['email' => 'super-host-login@platform.test', 'password' => 'password'])
            ->assertRedirect("http://{$this->adminHost}".route('login', absolute: false))
            ->assertSessionHas('status');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post("http://{$this->adminHost}/login", ['email' => 'super-host-login@platform.test', 'password' => 'password'])
            ->assertRedirect(route('superadmin.dashboard', absolute: false));
    }
}
