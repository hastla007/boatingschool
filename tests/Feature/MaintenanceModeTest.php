<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N9: maintenance_mode wurde gespeichert, aber nirgends
 * durchgesetzt.
 */
class MaintenanceModeTest extends TestCase
{
    use InteractsWithTenants;

    /**
     * Bug-Review Betrieb B3: platform_setting ist eine global geteilte
     * Singleton-Zeile (id = 1), kein pro-Test angelegter Datensatz --
     * ohne diesen Reset blieb maintenance_mode nach diesem Testlauf
     * dauerhaft auf true stehen. In zufälliger Testreihenfolge
     * (--order-by=random) schlugen dadurch alle nachfolgenden Tests
     * fehl, die irgendeine Tenant-Route aufrufen (503 statt der
     * erwarteten Antwort) -- die Test-Datenbank blieb bis zum nächsten
     * manuellen Reset dauerhaft im Wartungsmodus.
     */
    protected function tearDown(): void
    {
        $this->resetMaintenanceMode();

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function resetMaintenanceMode(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'maintenance_mode' => false,
            'maintenance_message' => null,
        ]));
    }

    public function test_tenant_traffic_sees_the_maintenance_page_when_enabled(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'maintenance_mode' => true,
            'maintenance_message' => 'Gleich geht es weiter.',
        ]));

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertStatus(503);
        $response->assertSee('Gleich geht es weiter.');
    }

    public function test_superadmin_can_still_reach_the_superadmin_area_during_maintenance(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update(['maintenance_mode' => true]));

        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get('/superadmin')->assertOk();
    }

    public function test_tenant_traffic_is_unaffected_when_maintenance_mode_is_off(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update(['maintenance_mode' => false]));

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk();
    }

    /**
     * Bug-Review Betrieb B3: reproduziert direkt das beobachtete Symptom --
     * nach einem Test, der maintenance_mode aktiviert, muss die
     * Singleton-Zeile wieder zurückgesetzt sein, sonst sieht jeder
     * nachfolgende Test (hier simuliert durch einen ganz normalen
     * Dashboard-Aufruf direkt danach) fälschlich 503 statt der
     * erwarteten Antwort.
     */
    public function test_maintenance_mode_is_reset_after_a_test_that_enabled_it(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update([
            'maintenance_mode' => true,
            'maintenance_message' => 'Gleich geht es weiter.',
        ]));

        // Simuliert das Ende dieses Tests, bevor der nächste (hier: die
        // Prüfung unten) startet.
        $this->resetMaintenanceMode();

        $this->onAdmin(function () {
            $setting = PlatformSetting::current()->fresh();
            $this->assertFalse($setting->maintenance_mode);
            $this->assertNull($setting->maintenance_message);
        });

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk();
    }
}
