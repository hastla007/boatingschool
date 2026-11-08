<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review "Gesperrte Schule": ResolveTenant prüfte status=closed/
 * suspended bisher nur im NICHT-mandantenunabhängigen Zweig -- auf der
 * Subdomain einer gesperrten Schule erschien deshalb trotzdem die normale
 * Login-Seite (samt deren Branding), statt direkt die Sperrseite zu
 * zeigen. Ein tatsächlicher Login-Versuch scheiterte zwar ohnehin (403,
 * keine Daten wurden offengelegt), die angezeigte Seite war aber falsch.
 */
class SuspendedTenantAgnosticRouteTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_login_page_shows_the_suspension_notice_on_a_suspended_tenants_subdomain(): void
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => $tenant->update(['status' => 'suspended']));

        // Review-Runde 10: Anmelden bleibt möglich (Datenauskunft/Löschung nach
        // Art. 15/17/20 DSGVO), die Seite weist auf die Sperre hin; alles
        // andere zeigt weiter die Sperrseite.
        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');
        $response->assertOk();
        $response->assertSee('vorübergehend gesperrt');
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register')->assertStatus(403);
    }

    public function test_the_login_page_shows_the_closed_notice_on_a_closed_tenants_subdomain(): void
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => $tenant->update(['status' => 'closed']));

        // Review-Runde 10: Anmelden bleibt möglich (Datenauskunft/Löschung nach
        // Art. 15/17/20 DSGVO), die Seite weist auf die Sperre hin; alles
        // andere zeigt weiter die Sperrseite.
        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');
        $response->assertOk();
        $response->assertSee('geschlossen');
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register')->assertStatus(403);
    }

    public function test_the_login_page_still_works_normally_on_an_active_tenants_subdomain(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertViewIs('auth.login');
    }
}
