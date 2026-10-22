<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B26: Superadmin\CouponController::export() validierte 'ids'
 * per required_if:select_all,0 -- das griff nur, wenn select_all exakt den
 * Wert "0" trug. Ein Request ganz ohne select_all (z. B. manuell
 * manipuliert, kein Formularfeld mitgesendet) bestand die Validierung
 * trotzdem, $validated['ids'] existierte dann nicht, und
 * whereIn('id', $validated['ids']) schlug mit einem ungefangenen TypeError
 * (HTTP 500) fehl statt einem sauberen Validierungsfehler.
 */
class CouponExportMalformedRequestTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_exporting_without_select_all_or_ids_is_rejected_cleanly(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons/export', []);

        $response->assertSessionHasErrors('ids');
    }

    public function test_exporting_with_select_all_explicitly_false_and_no_ids_is_rejected_cleanly(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons/export', [
            'select_all' => '0',
        ]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_exporting_with_select_all_true_and_no_ids_still_works(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Coupon::create(['code' => 'B26-EXPORT-TEST', 'course_id' => $course->id]));

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons/export', [
            'select_all' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('B26-EXPORT-TEST');

        $this->onAdmin(fn () => Coupon::where('code', 'B26-EXPORT-TEST')->delete());
    }
}
