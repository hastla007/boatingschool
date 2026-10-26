<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Support\GermanTime;
use Illuminate\Support\Carbon;
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

        // Bug-Review B14 (Folgefehler): travelTo() friert die Zeit über das
        // Testende hinaus ein, wenn hier nicht zurückgesetzt wird --
        // andernfalls liefen alle nachfolgenden Tests im selben Prozess
        // mit einer falschen "jetzt"-Zeit weiter.
        $this->travelBack();

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
        // Bug-Review N18: endet am Ende des gewählten Tages in deutscher
        // Zeit, nicht in UTC -- der rohe (UTC-)Stundenwert liegt je nach
        // Sommer-/Winterzeit bei 21 oder 22, nicht bei 23.
        $this->assertSame('23', GermanTime::format($entitlement->valid_until, 'H'));
        $this->assertTrue($entitlement->valid_until->gt($entitlement->valid_from));
    }

    /**
     * Bug-Review B14 (Folgefehler): endOfValidityDay() normalisiert nur auf
     * das Ende des gewählten Tages -- ein tatsächlich vergangenes Datum lag
     * damit immer noch vor valid_from (jetzt) und verletzte weiterhin den
     * DB-Check-Constraint (HTTP 500) statt einen Validierungsfehler zu
     * liefern.
     */
    public function test_granting_access_with_a_date_in_the_past_returns_a_validation_error_instead_of_crashing(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'valid_until' => now()->subDays(2)->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
        $this->onAdmin(fn () => $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]));
    }

    public function test_extending_an_entitlement_with_a_date_in_the_past_returns_a_validation_error_instead_of_crashing(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => now()->subDays(2)->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
    }

    /**
     * Bug-Review B14 (Folgefehler): zwischen dem deutschen Tagesende (in
     * UTC) und dem UTC-Tagesende selbst (hier: 23:00-24:00 UTC im Winter,
     * CET) besteht "heute" die Validierung oben (after_or_equal:today, in
     * UTC geprüft), aber endOfValidityDay() interpretiert "heute" in
     * deutscher Zeit (siehe N18) -- das Ergebnis lag dann bereits vor dem
     * eingefrorenen "jetzt" und verletzte weiterhin den DB-Check-
     * Constraint (HTTP 500) statt einen Validierungsfehler zu liefern.
     */
    public function test_granting_access_valid_until_today_between_11pm_and_midnight_utc_returns_a_validation_error_instead_of_crashing(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 23:30:00', 'UTC'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'valid_until' => '2026-01-15',
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
        $this->onAdmin(fn () => $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]));
    }

    public function test_extending_an_entitlement_valid_until_today_between_11pm_and_midnight_utc_returns_a_validation_error_instead_of_crashing(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 23:30:00', 'UTC'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $originalValidUntil = $entitlement->valid_until;

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => '2026-01-15',
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
        $this->assertEquals($originalValidUntil, $entitlement->fresh()->valid_until);
    }

    /**
     * Bug-Review N6: status wechselt nie automatisch zurück auf "active"
     * (nur ExpireEntitlements setzt ihn Richtung "expired") --
     * isCurrentlyActive() verlangt aber zwingend status=active,
     * unabhängig von valid_until. "Verlängern" eines bereits
     * abgelaufenen (status=expired) Zugangs blieb deshalb trotz neuem,
     * zukünftigem valid_until wirkungslos gesperrt.
     */
    public function test_extending_an_already_expired_entitlement_reactivates_access(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'expired',
            'source_type' => 'manual',
            'valid_from' => now()->subDays(400),
            'valid_until' => now()->subDays(30),
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => now()->addDays(365)->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $this->onAdmin(fn () => $entitlement->fresh());
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->isCurrentlyActive());

        $access = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $access->assertOk();
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
        $this->assertSame('23', GermanTime::format($entitlement->valid_until, 'H'));

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
