<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\TenantInvitation;

/**
 * Ein beim Einladen angegebener Code (Admin\ParticipantController::invite):
 * wird vorab geprüft -- ohne etwas anzulegen und für neue Adressen wie für
 * Bestandskonten gleich -- und dann bis zur Annahme an der Einladung
 * reserviert (TenantInvitation.coupon_code). Eingelöst wird er erst in
 * InvitationAcceptance.
 */
class InvitationCoupon
{
    public function __construct(private EntitlementService $entitlements) {}

    /**
     * Kurs- bzw. schulabhängige Prüfungen vorab. Personenbezogene Gründe
     * (z. B. Kurs bereits gebucht oder entzogen) können erst beim Annehmen
     * auftreten -- dann bleibt es still bei der Mitgliedschaft.
     *
     * @return array{coupon: ?Coupon, error: ?string}
     */
    public function check(Tenant $tenant, string $code, string $email): array
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->whereNull('redeemed_at')->first();

        if (! $coupon || ! $coupon->isUsable() || ($coupon->tenant_id !== null && $coupon->tenant_id !== $tenant->id)) {
            return ['coupon' => null, 'error' => 'Dieser Code ist ungültig oder wurde bereits eingelöst.'];
        }

        if ($coupon->isForProduct()) {
            if (! $coupon->product || ! $coupon->product->active) {
                return ['coupon' => null, 'error' => 'Dieses Produkt ist aktuell nicht verfügbar.'];
            }
        } else {
            $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);
            if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
                return ['coupon' => null, 'error' => 'Dieser Kurs ist aktuell nicht verfügbar.'];
            }
        }

        $reservedForOther = TenantInvitation::where('tenant_id', $tenant->id)->open()->where('coupon_code', $coupon->code)
            ->whereRaw('lower(email) <> ?', [mb_strtolower($email)])->exists();
        if ($reservedForOther) {
            return ['coupon' => null, 'error' => 'Dieser Code ist bereits für eine andere offene Einladung reserviert.'];
        }

        return ['coupon' => $coupon, 'error' => null];
    }

    /**
     * Codes, die gerade für eine offene Einladung dieser Schule reserviert
     * sind (Anzeige „reserviert für Einladung“ in der Coupon-Code-Liste).
     *
     * @param  iterable<string>  $codes
     * @return array<string, true>
     */
    public static function reservedCodes(Tenant $tenant, iterable $codes): array
    {
        $codes = collect($codes)->filter()->values();
        if ($codes->isEmpty()) {
            return [];
        }

        return TenantInvitation::where('tenant_id', $tenant->id)->open()->whereIn('coupon_code', $codes)
            ->pluck('coupon_code')->mapWithKeys(fn ($code) => [$code => true])->all();
    }
}
