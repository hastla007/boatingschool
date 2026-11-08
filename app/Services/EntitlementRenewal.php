<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Support\AccessGrantedMail;

/**
 * Kurszugang verlängern (Bootsschul-Admin, einzeln und als Sammelaktion):
 * verbraucht einen freien Verlängerungscode des Kurses und verlängert um
 * tenant_branding.course_validity_days ab dem späteren von valid_until und
 * jetzt, damit keine Restlaufzeit verloren geht. Abgelaufene Zugänge werden
 * wieder aktiv; Sperre und Widerruf hebt eine Verlängerung nicht auf.
 *
 * Muss innerhalb einer Transaktion laufen (Code-Sperre „skip locked“).
 */
class EntitlementRenewal
{
    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public function extend(Entitlement $entitlement, Tenant $tenant): ?string
    {
        if ($entitlement->status === 'revoked') {
            return 'Der Zugang zu diesem Kurs wurde widerrufen und lässt sich nicht einfach verlängern – bitte zuerst den Widerruf aufheben.';
        }

        if ($entitlement->valid_until === null) {
            return 'Dieser Zugang ist bereits unbefristet gültig.';
        }

        $coupon = Coupon::where('tenant_id', $tenant->id)
            ->where('course_id', $entitlement->course_id)
            ->where('is_renewal', true)
            ->whereNull('redeemed_at')
            ->usable()
            ->oldest()
            ->lock('for update skip locked')
            ->first();

        if (! $coupon) {
            return 'Für diesen Kurs ist kein freier Verlängerungscode mehr vorhanden.';
        }

        $base = $entitlement->valid_until->gt(now()) ? $entitlement->valid_until : now();

        $entitlement->update([
            'valid_until' => $base->copy()->addDays($tenant->branding->course_validity_days),
            'status' => $entitlement->status === 'expired' ? 'active' : $entitlement->status,
        ]);

        $coupon->update([
            'redeemed_by_user_id' => $entitlement->user_id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => now(),
        ]);

        AccessGrantedMail::afterCommit($entitlement, 'course_renewal');

        return null;
    }

    public function freeCodes(Tenant $tenant, string $courseId): int
    {
        return Coupon::where('tenant_id', $tenant->id)->where('course_id', $courseId)->where('is_renewal', true)
            ->whereNull('redeemed_at')->usable()->count();
    }
}
