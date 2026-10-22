<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B16: site_name und support_email der Plattform-Einstellungen
 * wurden gespeichert, aber nirgends angezeigt -- Wartungsmodus selbst war
 * bereits durch Sicherheitsreview N9 durchgesetzt.
 */
class PlatformSettingsEffectTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        // Die Singleton-Zeile (id=1) wird von anderen Tests geteilt --
        // zurücksetzen statt löschen, siehe N9/MaintenanceModeTest.
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'site_name' => 'Bootsführerschein-Lernplattform',
            'support_email' => null,
            'maintenance_mode' => false,
            'maintenance_message' => null,
        ]));

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_platform_site_name_appears_in_the_guest_page_title(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update(['site_name' => 'Testname GmbH Lernplattform']));

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('<title>Testname GmbH Lernplattform</title>', false);
    }

    public function test_the_platform_site_name_appears_in_the_superadmin_page_title(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update(['site_name' => 'Testname GmbH Lernplattform']));

        $superadmin = $this->createSuperAdmin();
        $response = $this->actingAs($superadmin)->get('/superadmin');

        $response->assertOk();
        $response->assertSee('<title>Superadmin &middot; Testname GmbH Lernplattform</title>', false);
    }

    public function test_the_support_email_appears_on_the_maintenance_page(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'maintenance_mode' => true,
            'support_email' => 'support@example.test',
        ]));

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertStatus(503);
        $response->assertSee('support@example.test');
        $response->assertSee('mailto:support@example.test', false);
    }

    public function test_no_support_contact_is_shown_when_no_support_email_is_configured(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'maintenance_mode' => true,
            'support_email' => null,
        ]));

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertStatus(503);
        $response->assertDontSee('mailto:', false);
    }
}
