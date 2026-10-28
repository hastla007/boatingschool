<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Fehlermeldungen erscheinen doppelt): layouts/app.blade.php
 * zeigt Fehlermeldungen, die keine eigene Anzeige neben ihrem Feld haben
 * (siehe Bug-Review T4), seit T4 zusätzlich in einer globalen Box oben auf
 * der Seite. Diese Box rendert bisher $errors->all() komplett -- auch
 * Meldungen, die eine Seite bereits direkt neben ihrem Feld anzeigt, z. B.
 * den "assign"-Fehler der Coupon-Zuweisung (admin/tabs/coupons.blade.php).
 * Jede so eingeleitete Meldung erschien dadurch zweimal auf derselben
 * Seite.
 */
class GlobalErrorBoxDoesNotDuplicateFieldErrorsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_coupon_assignment_error_is_not_duplicated_in_the_global_error_box(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-ERRBOX-'.uniqid();
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenantA->id]));

        $dashboard = route('admin.dashboard', ['tab' => 'coupons']);

        $this->actingAsInTenant($adminA, $tenantA)
            ->from($dashboard)
            ->post("/admin/coupons/{$coupon->id}/assign", ['user_id' => $learnerB->id])
            ->assertRedirect($dashboard);

        $response = $this->actingAsInTenant($adminA, $tenantA)->get($dashboard);
        $response->assertOk();

        $occurrences = substr_count($response->getContent(), 'gehört nicht zu');

        $this->assertSame(1, $occurrences, 'Der Coupon-Zuweisungsfehler darf nicht zusätzlich in der globalen Fehlerbox erscheinen.');
    }
}
