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
 * Einlösen eines Coupons für einen Kurs (Entitlement), ein Produkt
 * (ProductPurchase) oder einen Verlängerungscode (verlängert ein
 * bestehendes Entitlement statt ein neues anzulegen, siehe
 * redeemForRenewal) -- Kernlogik geteilt zwischen dem Self-Service-Einlösen
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

    /** @return array{type: 'course'|'product'|'course_renewal', course?: CourseDefinition, product?: \App\Models\Product, entitlement?: Entitlement} */
    public function redeem(Coupon $coupon, Tenant $tenant, User $user, ?User $actor = null): array
    {
        $actor ??= $user;

        return DB::transaction(function () use ($coupon, $tenant, $user, $actor) {
            // Zeile sperren und erst danach frisch lesen: verhindert, dass
            // zwei gleichzeitige Einlöse-Versuche desselben Codes (Self-
            // Service-Doppelklick, zwei Konten mit demselben Code, oder
            // Self-Service parallel zur Admin-Zuweisung) beide isRedeemed()
            // == false sehen. Der zweite Request wartet an dieser Stelle,
            // bis der erste committet hat, und bricht danach kontrolliert ab
            // (siehe Sicherheitsreview H1).
            $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();

            if (! $locked || $locked->isRedeemed()) {
                throw new RuntimeException('Dieser Code wurde bereits eingelöst.');
            }

            $coupon = $locked;

            if ($coupon->isForRenewal()) {
                return $this->redeemForRenewal($coupon, $tenant, $user, $actor);
            }

            return $coupon->isForProduct()
                ? $this->redeemForProduct($coupon, $tenant, $user, $actor)
                : $this->redeemForCourse($coupon, $tenant, $user, $actor);
        });
    }

    private function redeemForCourse(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
            throw new RuntimeException('Dieser Kurs ist aktuell nicht verfügbar.');
        }

        $now = now();

        $entitlement = Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $coupon->course_id,
            'valid_from' => $now,
            'valid_until' => $now->copy()->addDays($tenant->branding->course_validity_days),
            'status' => 'active',
            'source_type' => 'coupon',
            'source_reference' => $coupon->code,
        ]);

        $this->applyOptionProducts($coupon, $tenant, $user);

        $coupon->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['course_id' => $coupon->course_id, 'user_id' => $user->id]);

        return ['type' => 'course', 'course' => $course, 'entitlement' => $entitlement];
    }

    /**
     * Verlängert ein Kurs-Entitlement statt ein neues anzulegen: "Ablauf 1"
     * (Teilnehmer löst selbst über CouponRedeemController ein) und "Ablauf 2"
     * (Bootsschul-Admin weist über Admin\CouponController::assign direkt
     * einem Teilnehmer zu) laufen beide über diese eine Methode. Läuft das
     * bestehende Entitlement noch, verlängert sich sein valid_until ab dem
     * aktuellen Ablaufdatum (nicht ab heute) -- eine ungenutzte Restlaufzeit
     * geht also nicht verloren. Ist es bereits abgelaufen (oder gab es noch
     * nie eines für diesen Kurs), zählt die Frist ab heute. In beiden Fällen
     * wird der Status wieder auf "active" gesetzt, auch wenn er zwischenzeitlich
     * suspendiert war -- ein gekaufter Verlängerungscode soll den Zugang
     * zuverlässig zurückbringen.
     */
    private function redeemForRenewal(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
            throw new RuntimeException('Dieser Kurs ist aktuell nicht verfügbar.');
        }

        $now = now();
        $days = $tenant->branding->course_validity_days;

        $entitlement = Entitlement::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderByDesc('valid_until')
            ->first();

        if ($entitlement) {
            $base = ($entitlement->valid_until && $entitlement->valid_until->gt($now)) ? $entitlement->valid_until : $now;
            $entitlement->update(['valid_until' => $base->copy()->addDays($days), 'status' => 'active']);
        } else {
            $entitlement = Entitlement::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'valid_from' => $now,
                'valid_until' => $now->copy()->addDays($days),
                'status' => 'active',
                'source_type' => 'coupon_renewal',
                'source_reference' => $coupon->code,
            ]);
        }

        $coupon->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem_renewal', 'coupon', $coupon->id, null, [
            'course_id' => $course->id, 'user_id' => $user->id, 'valid_until' => $entitlement->valid_until?->toIso8601String(),
        ]);

        return ['type' => 'course_renewal', 'course' => $course, 'entitlement' => $entitlement];
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

        $now = now();

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
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['product_id' => $product->id, 'user_id' => $user->id]);

        return ['type' => 'product', 'product' => $product];
    }
}
