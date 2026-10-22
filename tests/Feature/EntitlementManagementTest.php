<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B14 (Admin\EntitlementController): "gültig bis heute" führte zu
 * einem 500er, weil ein reines Datum als Mitternacht (Tagesbeginn)
 * gespeichert wurde -- das lag vor valid_from (jetzt) und verletzte den
 * DB-Check-Constraint; außerdem endete der Zugang dadurch schon am
 * gewählten Tag selbst statt an seinem Ende. Ein Doppelklick auf
 * "Freischalten" konnte zwei Entitlement-Zeilen für denselben Kurs anlegen.
 * "Verlängern" und "Sperren" existierten im Controller, aber ohne jede
 * Oberfläche.
 */
class EntitlementManagementTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_granting_access_valid_until_today_does_not_crash_and_keeps_access_until_end_of_day(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'valid_until' => now()->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());

        $this->assertTrue($entitlement->valid_until->isSameDay(now()));
        $this->assertSame(23, $entitlement->valid_until->hour);
        $this->assertTrue($entitlement->valid_until->gt($entitlement->valid_from));
    }

    public function test_double_submitting_the_grant_form_does_not_create_a_duplicate_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $payload = ['user_id' => $learner->id, 'course_id' => $course->id];
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', $payload);
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', $payload);

        $this->assertSame(1, $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->count()));
    }

    public function test_granting_access_again_reactivates_a_previously_revoked_entitlement_instead_of_duplicating(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail());
        // Statt $entitlement->update(...): die Instanz trägt die Connection
        // fest, die beim Laden "default" war -- siehe
        // ExpireAbandonedExamSessionsTest für die ausführliche Begründung.
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'revoked']));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);

        $this->assertSame(1, $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->count()));
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('active', $entitlement->status);
    }

    public function test_admin_can_extend_and_suspend_an_entitlement_through_the_participant_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $detail = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $detail->assertOk();
        $detail->assertSee(route('admin.entitlements.update', $entitlement), false);
        $detail->assertSee('Verlängern');
        $detail->assertSee('Sperren');

        $newValidUntil = now()->addDays(10)->format('Y-m-d');
        $extend = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'extend',
            'valid_until' => $newValidUntil,
        ]);
        $extend->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertTrue($entitlement->valid_until->isSameDay(now()->addDays(10)));
        $this->assertSame(23, $entitlement->valid_until->hour);

        $suspend = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'suspend',
        ]);
        $suspend->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('suspended', $entitlement->status);
    }

    public function test_an_instructor_does_not_see_the_extend_or_suspend_controls(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $detail = $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$learner->id}");

        $detail->assertOk();
        $detail->assertDontSee('Verlängern');
        $detail->assertDontSee('Sperren');
    }
}
