<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuperadminDashboardMetricsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_dashboard_shows_activity_exam_and_expiry_metrics(): void
    {
        $superadmin = $this->createSuperAdmin();
        $quiet = $this->createTestTenant('Stille Schule '.uniqid());
        $learner = $this->createTenantUser($quiet, 'learner');
        $entitlement = $this->grantEntitlement($quiet, $learner, $this->existingCourse('SRC'));
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_until' => now()->addDays(5)]));

        $page = $this->actingAs($superadmin)->get('/superadmin')->assertOk();
        $page->assertSee('Aktive Lernende (30 Tage)')->assertSee('Bestehensquote')->assertSee('Kurszugänge laufen in 14 Tagen ab');
        $page->assertSee($quiet->name);
        $page->assertViewHas('expiringCount', fn ($count) => $count >= 1);
        $page->assertViewHas('inactiveTenants', fn ($tenants) => $tenants->contains('id', $quiet->id));
    }
}
