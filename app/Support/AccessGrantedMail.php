<?php

namespace App\Support;

use App\Models\Entitlement;
use App\Notifications\CouponRedeemed;
use Illuminate\Support\Facades\DB;

/**
 * Review-Runde 8: schaltet die Schule einen Kurs frei oder verlängert ihn,
 * erfährt die/der Lernende davon per Mail (dieselbe Mail wie beim selbst
 * eingelösten Code). Erst nach erfolgreichem Commit, Fehler brechen nichts ab.
 *
 * Audit ARC-2: über die Queue statt defer() im Request -- eine Sammel-
 * Verlängerung (bis zu 500 Zugänge) hing sonst am SMTP-Server und konnte vom
 * PHP-FPM-Zeitlimit abgebrochen werden (Mails verloren). Je Zugang ein Job,
 * im Namen der Schule des Zugangs.
 */
class AccessGrantedMail
{
    /** @param 'course'|'course_renewal' $type */
    public static function afterCommit(Entitlement $entitlement, string $type): void
    {
        DB::afterCommit(function () use ($entitlement, $type) {
            $entitlement = $entitlement->fresh(['user', 'course']);
            if (! $entitlement?->user || ! $entitlement->course) {
                return;
            }
            BestEffortMail::send(
                fn () => $entitlement->user->notify(new CouponRedeemed(['type' => $type, 'course' => $entitlement->course, 'entitlement' => $entitlement])),
                "Freischaltungsmail {$entitlement->id}"
            );
        });
    }
}
