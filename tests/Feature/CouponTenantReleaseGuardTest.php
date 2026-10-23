<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Illuminate\Database\QueryException;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M7 (Folgefehler): coupon.tenant_id ist $fillable und
 * die RLS-Policy erlaubt (bewusst, für die Claim-/Einlöse-Abläufe)
 * tenant_id IS NULL -- ohne zusätzliche Absicherung könnte ein künftiger
 * Mass-Assignment-Bug einen bereits einer Bootsschule zugewiesenen
 * Coupon zurück in den plattformweit einlösbaren Pool "entlassen". Der
 * coupon_prevent_tenant_release-Trigger (Migration 2024_02_01_000467)
 * verhindert genau diese eine Übergangsrichtung.
 */
class CouponTenantReleaseGuardTest extends TestCase
{
    use InteractsWithTenants;

    public function test_releasing_an_assigned_coupon_back_to_unassigned_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));

        try {
            $this->onAdmin(fn () => $coupon->update(['tenant_id' => null]));
            $this->fail('Das Zurücksetzen von tenant_id auf NULL hätte an der DB abgelehnt werden müssen.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('kann nicht auf NULL zurückgesetzt werden', $e->getMessage());
        }

        $this->onAdmin(fn () => Coupon::where('id', $coupon->id)->delete());
    }

    /**
     * coupon.tenant_id ist bewusst nullOnDelete() -- ein Coupon überlebt die
     * Löschung seiner zugewiesenen Bootsschule, statt mit ihr zu
     * verschwinden. Diese FK-Kaskade löst intern dasselbe UPDATE aus wie der
     * zu verhindernde Mass-Assignment-Fall und darf vom Trigger nicht
     * blockiert werden.
     */
    public function test_deleting_the_assigned_tenant_still_nulls_the_coupon_via_the_fk_cascade(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));

        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () use ($coupon) {
            $this->assertNull($coupon->fresh()->tenant_id);
            Coupon::where('id', $coupon->id)->delete();
        });
    }

    /** Claim-Ablauf (Admin\CouponController::import): NULL -> eigene UUID muss weiter funktionieren. */
    public function test_claiming_an_unassigned_coupon_still_works(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));

        $this->onAdmin(fn () => $coupon->update(['tenant_id' => $tenant->id]));

        $this->assertSame($tenant->id, $coupon->fresh()->tenant_id);
        $this->onAdmin(fn () => Coupon::where('id', $coupon->id)->delete());
    }

    /** Einlöse-Ablauf (CouponRedemptionService) für einen freien Code: tenant_id bleibt NULL. */
    public function test_redeeming_an_unassigned_coupon_without_changing_tenant_id_still_works(): void
    {
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));

        $this->onAdmin(fn () => $coupon->update(['redeemed_at' => now()]));

        $this->assertNotNull($coupon->fresh()->redeemed_at);
        $this->assertNull($coupon->fresh()->tenant_id);
        $this->onAdmin(fn () => Coupon::where('id', $coupon->id)->delete());
    }
}
