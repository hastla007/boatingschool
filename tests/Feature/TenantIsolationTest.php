<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Deckt die zentralen Abnahmekriterien aus dem Umsetzungskonzept ab:
 * "Eine Bootsschule kann keine Nutzer/Codes/Entitlements/Fortschrittsdaten
 * einer anderen Bootsschule lesen oder verändern" und "Direkte
 * ID-Manipulation liefert 404/403". Läuft über die echte App-Rolle, RLS ist
 * also tatsächlich aktiv -- nicht nur der Eloquent-Scope.
 */
class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_admin_cannot_see_participants_of_a_different_tenant(): void
    {
        $tenantA = $this->createTestTenant('Bootsschule A');
        $tenantB = $this->createTestTenant('Bootsschule B');

        $adminA = $this->createTenantUser($tenantA, 'admin');
        $this->createTenantUser($tenantB, 'learner');

        // adminA ist kein Mitglied von tenantB -> Middleware muss blocken.
        $response = $this->actingAsInTenant($adminA, $tenantB)->get('/admin/participants');

        $response->assertForbidden();
    }

    public function test_direct_entitlement_id_from_other_tenant_returns_404_and_leaves_it_unchanged(): void
    {
        $tenantA = $this->createTestTenant('Bootsschule A');
        $tenantB = $this->createTestTenant('Bootsschule B');

        $adminA = $this->createTenantUser($tenantA, 'admin');
        $learnerB = $this->createTenantUser($tenantB, 'learner');

        $course = $this->existingCourse('SBF-SEE');
        $entitlementB = $this->grantEntitlement($tenantB, $learnerB, $course);

        $response = $this->actingAsInTenant($adminA, $tenantA)
            ->patch("/admin/entitlements/{$entitlementB->id}", ['action' => 'revoke']);

        $response->assertNotFound();
        $this->assertDatabaseHas('entitlement', ['id' => $entitlementB->id, 'status' => 'active'], 'pgsql_admin');
    }

    public function test_learner_without_entitlement_cannot_open_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertForbidden();
    }

    public function test_learner_with_active_entitlement_can_open_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
    }

    public function test_expired_entitlement_no_longer_grants_access(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');

        $this->onAdmin(fn () => \App\Models\Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'manual',
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->subDay(),
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertForbidden();
    }
}
