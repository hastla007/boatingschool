<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review T4: Admin\EntitlementController::store()/update() liefern bei
 * fehlender Code-Verfügbarkeit (Freischalten/Verlängern/Widerruf aufheben)
 * back()->withErrors([...]) -- das bestehende EntitlementManagementTest
 * prüft dafür bisher nur assertSessionHasErrors(), also ob die Fehlermeldung
 * überhaupt in der Session landet, nie ob sie auf der Seite, auf die back()
 * zurückführt, tatsächlich ANGEZEIGT wird. layouts/app.blade.php rendert
 * dort bis dahin nur session('status') global -- $errors (course_id/action)
 * erreichte weder admin/tabs/participants.blade.php noch
 * admin/participants/show.blade.php. Der Admin sah nach einem Klick auf
 * "Freischalten"/"Verlängern"/"Widerruf aufheben" ohne freien Code dadurch
 * überhaupt keine Rückmeldung.
 */
class EntitlementErrorsAreVisibleInTheUiTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_failed_grant_without_a_free_coupon_shows_the_error_on_the_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        // Bewusst kein Coupon für diesen Kurs angelegt.

        $dashboard = route('admin.dashboard', ['tab' => 'participants']);

        $this->actingAsInTenant($admin, $tenant)
            ->from($dashboard)
            ->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertRedirect($dashboard);

        $this->actingAsInTenant($admin, $tenant)
            ->get($dashboard)
            ->assertSee('Für diesen Kurs ist kein freier Code mehr vorhanden.');
    }

    public function test_a_failed_extend_without_a_free_renewal_coupon_shows_the_error_on_the_dashboard(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_until' => now()->addDays(5)]));
        // Bewusst kein Verlängerungscode für diesen Kurs angelegt.

        $dashboard = route('admin.dashboard', ['tab' => 'participants']);

        $this->actingAsInTenant($admin, $tenant)
            ->from($dashboard)
            ->patch("/admin/entitlements/{$entitlement->id}", ['action' => 'extend'])
            ->assertRedirect($dashboard);

        $this->actingAsInTenant($admin, $tenant)
            ->get($dashboard)
            ->assertSee('Für diesen Kurs ist kein freier Verlängerungscode mehr vorhanden.');
    }
}
