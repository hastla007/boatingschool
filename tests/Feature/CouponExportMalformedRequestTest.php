<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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

    /**
     * Bug-Review R4-J: der feste Testcode (ohne uniqid()-Suffix) wurde
     * bisher erst NACH den eigenen Assertions im Testkörper selbst
     * gelöscht -- schlug eine Assertion fehl, lief dieses Aufräumen nie,
     * und der Code blieb dauerhaft stehen. Ein späterer Testlauf scheiterte
     * dann an genau diesem Code mit einer UniqueConstraintViolationException,
     * unabhängig vom eigentlich zu prüfenden Verhalten.
     */
    private array $createdCouponCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCouponCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

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
        $this->createdCouponCodes[] = 'B26-EXPORT-TEST';
        $this->onAdmin(fn () => Coupon::create(['code' => 'B26-EXPORT-TEST', 'course_id' => $course->id]));

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons/export', [
            'select_all' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('B26-EXPORT-TEST');

        // Bug-Review (Testdaten bleiben weiter liegen): ohne tenant_id im
        // Request protokolliert Superadmin\CouponController::export()
        // bewusst mit tenant_id=null -- die automatische AuditLog-
        // Aufräumung über einen Test-Tenant greift hier nicht, da dieser
        // Test gar keinen Tenant anlegt.
        $this->onAdmin(fn () => AuditLog::where('action', 'superadmin.coupon.export')->where('actor_user_id', $superadmin->id)->delete());
    }
}
