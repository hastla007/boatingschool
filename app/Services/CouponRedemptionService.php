<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Einlösen eines Coupons für einen Kurs (Entitlement) oder ein Produkt
 * (ProductPurchase) -- Kernlogik geteilt zwischen dem Self-Service-Einlösen
 * (CouponRedeemController, $user löst für sich selbst ein) und der
 * Zuweisung durch den Bootsschul-Admin an einen bestimmten Nutzer
 * (Admin\CouponController::assign, $user erhält den Code, $actor ist der
 * Admin -- relevant für den Audit-Log-Eintrag).
 */
class CouponRedemptionService
{
    public function __construct(private EntitlementService $entitlements)
    {
    }

    /** @return array{type: 'course'|'product', course?: CourseDefinition, product?: \App\Models\Product} */
    public function redeem(Coupon $coupon, Tenant $tenant, User $user, ?User $actor = null): array
    {
        if ($coupon->isRedeemed()) {
            throw new RuntimeException('Dieser Code wurde bereits eingelöst.');
        }

        return $coupon->isForProduct()
            ? $this->redeemForProduct($coupon, $tenant, $user, $actor ?? $user)
            : $this->redeemForCourse($coupon, $tenant, $user, $actor ?? $user);
    }

    private function redeemForCourse(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
            throw new RuntimeException('Dieser Kurs ist aktuell nicht verfügbar.');
        }

        $entitlement = DB::transaction(function () use ($coupon, $tenant, $user) {
            $entitlement = Entitlement::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $coupon->course_id,
                'valid_from' => now(),
                'status' => 'active',
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);

            $this->applyOptionProducts($coupon, $tenant, $user);

            $coupon->update([
                'redeemed_by_user_id' => $user->id,
                'redeemed_tenant_id' => $tenant->id,
                'redeemed_at' => now(),
            ]);

            return $entitlement;
        });

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['course_id' => $coupon->course_id, 'user_id' => $user->id]);

        return ['type' => 'course', 'course' => $course, 'entitlement' => $entitlement];
    }

    /**
     * Ein Kurs-Coupon kann zusätzlich mehrere Produkte bündeln (siehe
     * coupon_option_product, z. B. "Lernplattform inkl. Willkommensbox" und
     * eine Praxiseinheit gleichzeitig) -- bei Einlösung entsteht dafür je
     * Option eine eigene ProductPurchase neben dem Kurs-Entitlement, genau
     * wie bei einem eigenständigen Produkt-Coupon.
     *
     * Für Praxiseinheiten gilt zusätzlich: eine "Große Praxiseinheit"
     * ersetzt eine bereits vorhandene "Kleine Praxiseinheit" -- der Kunde
     * hat danach nur noch die größere Einheit gebucht, nicht beide
     * nebeneinander. Die Löschung läuft vor den Neuanlagen, damit das auch
     * gilt, wenn ein Code beide Praxiseinheiten gleichzeitig bündelt.
     */
    private function applyOptionProducts(Coupon $coupon, Tenant $tenant, User $user): void
    {
        $options = $coupon->optionProducts;

        if ($options->isEmpty()) {
            return;
        }

        if ($options->contains('code', 'PRAXISEINHEIT-GROSS')) {
            ProductPurchase::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->whereHas('product', fn ($q) => $q->where('code', 'PRAXISEINHEIT-KLEIN'))
                ->delete();

            // Bündelt derselbe Code ausnahmsweise beide Praxiseinheiten
            // gleichzeitig, zählt nur die größere -- sonst würde der
            // nachfolgende Anlage-Schritt die gerade gelöschte Kleine sofort
            // wieder neu anlegen.
            $options = $options->reject(fn ($option) => $option->code === 'PRAXISEINHEIT-KLEIN');
        }

        foreach ($options as $option) {
            ProductPurchase::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'product_id' => $option->id,
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);
        }
    }

    private function redeemForProduct(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $product = $coupon->product;

        if (! $product || ! $product->active) {
            throw new RuntimeException('Dieses Produkt ist aktuell nicht verfügbar.');
        }

        DB::transaction(function () use ($coupon, $tenant, $user, $product) {
            ProductPurchase::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);

            $coupon->update([
                'redeemed_by_user_id' => $user->id,
                'redeemed_tenant_id' => $tenant->id,
                'redeemed_at' => now(),
            ]);
        });

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['product_id' => $product->id, 'user_id' => $user->id]);

        return ['type' => 'product', 'product' => $product];
    }
}
