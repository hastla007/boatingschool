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
}
