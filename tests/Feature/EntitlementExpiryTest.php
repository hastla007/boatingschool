<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B15: entitlement.status wechselte nie automatisch auf
 * "expired" (kein Scheduler-Lauf dafür), und die Admin-/Superadmin-
 * Dashboards zählten solche Zeilen anhand des (dadurch veralteten)
 * status=active weiter als aktiv.
 */
class EntitlementExpiryTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_expire_command_marks_past_due_entitlements_as_expired_and_leaves_others_untouched(): void
    {
        $tenant = $this->createTestTenant();
        $learnerA = $this->createTenantUser($tenant, 'learner');
        $learnerB = $this->createTenantUser($tenant, 'learner');
        $learnerC = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $expired = $this->grantEntitlement($tenant, $learnerA, $course);
        $this->onAdmin(fn () => $expired->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]));

        $stillValid = $this->grantEntitlement($tenant, $learnerB, $course);
        $this->onAdmin(fn () => $stillValid->update(['valid_until' => now()->addDay()]));

        $alreadyRevoked = $this->grantEntitlement($tenant, $learnerC, $course);
        $this->onAdmin(fn () => $alreadyRevoked->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay(), 'status' => 'revoked']));

        Artisan::call('app:expire-entitlements');

        $this->assertSame('expired', $expired->refresh()->status);
        $this->assertSame('active', $stillValid->refresh()->status);
        $this->assertSame('revoked', $alreadyRevoked->refresh()->status);
    }

    public function test_the_admin_dashboard_does_not_count_an_expired_entitlement_as_active(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => $entitlement->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewHas('activeEntitlements', 0);
    }

    public function test_the_superadmin_dashboard_does_not_count_an_expired_entitlement_as_active(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $superadmin = $this->createSuperAdmin();

        // Zählt plattformweit über alle Mandanten -- die Baseline vor der
        // eigenen (abgelaufenen) Freischaltung erfassen, statt 0 zu
        // erwarten, da die Dev-/Testdatenbank bereits andere, echte aktive
        // Entitlements enthalten kann.
        $baseline = $this->actingAs($superadmin)->get(route('superadmin.dashboard'))->viewData('activeEntitlementCount');

        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => $entitlement->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]));

        $response = $this->actingAs($superadmin)->get(route('superadmin.dashboard'));

        $response->assertOk();
        $response->assertViewHas('activeEntitlementCount', fn ($count) => $count === $baseline);
    }

    public function test_a_learner_with_only_an_expired_entitlement_shows_as_not_started_not_learning(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => $entitlement->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $phases = collect($response->viewData('learnerPhases'));
        $this->assertSame(0, $phases->firstWhere('label', 'Lernen')['count']);
        $this->assertSame(1, $phases->firstWhere('label', 'Nicht gestartet')['count']);
    }
}
