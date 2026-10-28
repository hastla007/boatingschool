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
     * Bug-Review (Gelöschte Schule macht ihre Coupons plattformweit
     * einlösbar): coupon.tenant_id ist nullOnDelete() -- ohne den
     * tenant_delete_unredeemed_coupons-Trigger (Migration
     * 2024_02_01_000473) hätte die FK-Kaskade einen noch nicht eingelösten
     * Coupon beim Löschen seiner Bootsschule genau in den "unzugeordnet"-
     * Zustand versetzt, den coupon_isolation für JEDE Bootsschule sichtbar
     * und einlösbar macht. Der Trigger löscht solche Coupons jetzt vorher
     * -- sie überleben die Schule nicht mehr.
     */
    public function test_deleting_the_assigned_tenant_deletes_its_unredeemed_coupon_instead_of_releasing_it(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));

        $this->cleanUpCreatedTenants();

        $this->onAdmin(fn () => $this->assertNull(Coupon::find($coupon->id), 'Der nicht eingelöste Coupon hätte mit seiner Bootsschule gelöscht werden müssen, statt plattformweit einlösbar zu werden.'));
    }

    /**
     * Ein bereits eingelöster Coupon ist Audit-Trail (redeemed_at schließt
     * eine erneute Einlösung ohnehin aus) -- der Trigger löscht
     * ausdrücklich nur WHERE redeemed_at IS NULL, die FK-Kaskade darf ihn
     * weiterhin wie bisher nullen statt zu löschen.
     */
    public function test_deleting_the_assigned_tenant_keeps_an_already_redeemed_coupon_with_tenant_id_nulled(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
            'redeemed_at' => now(),
            'redeemed_tenant_id' => $tenant->id,
        ]));

        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () use ($coupon) {
            $fresh = $coupon->fresh();
            $this->assertNotNull($fresh, 'Ein bereits eingelöster Coupon darf nicht gelöscht werden.');
            $this->assertNull($fresh->tenant_id);
            $this->assertNull($fresh->redeemed_tenant_id);
            $this->assertNotNull($fresh->redeemed_at);

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
