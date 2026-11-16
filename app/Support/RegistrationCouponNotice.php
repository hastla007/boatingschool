<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Ergebnis des Zugangscodes aus dem Registrierungsformular. Die
 * Registrierung meldet sich bewusst nicht an und antwortet für neue und
 * bestehende Adressen gleich (siehe RegisteredUserController) -- das
 * Ergebnis wird deshalb je Konto zwischengespeichert und nach der ersten
 * Anmeldung einmalig angezeigt (auch auf einem anderen Gerät, z. B. nach
 * dem Bestätigungslink auf dem Handy).
 */
class RegistrationCouponNotice
{
    public const SESSION_KEY = 'registration_coupon_notice';

    /** @param array{type: 'success'|'error', message: string} $notice */
    public static function remember(User $user, array $notice): void
    {
        Cache::put(self::cacheKey($user->id), $notice, now()->addDays(14));
    }

    /** Beim Anmelden (Login-Event) in die Sitzung übernehmen. */
    public static function moveToSession(User $user): void
    {
        $notice = Cache::pull(self::cacheKey($user->id));

        if (is_array($notice) && request()->hasSession()) {
            request()->session()->put(self::SESSION_KEY, $notice);
        }
    }

    /** @return array{type: 'success'|'error', message: string}|null Einmalig anzeigen. */
    public static function pull(): ?array
    {
        if (! request()->hasSession()) {
            return null;
        }

        $notice = request()->session()->pull(self::SESSION_KEY);

        return is_array($notice) ? $notice : null;
    }

    private static function cacheKey(string $userId): string
    {
        return 'registration-coupon-notice:'.$userId;
    }
}
