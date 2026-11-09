<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Zweiten Faktor prüfen: TOTP-Code (jeder Zeitschritt nur einmal) oder ein
 * Wiederherstellungscode (wird dabei verbraucht). Gemeinsam für Web-Login
 * und Mobile-API.
 */
class TwoFactorCheck
{
    public static function verify(User $user, string $code, string $recoveryCode): bool
    {
        $code = trim($code);
        $recoveryCode = trim($recoveryCode);

        if ($code !== '') {
            $lastStep = cache()->get('two-factor-step:'.$user->id);
            $step = Totp::verify($user->two_factor_secret, $code, null, $lastStep);
            if ($step === null) {
                return false;
            }
            cache()->put('two-factor-step:'.$user->id, $step, now()->addMinutes(5));

            return true;
        }

        if ($recoveryCode !== '') {
            $hashes = $user->two_factor_recovery_codes ?? [];
            foreach ($hashes as $i => $hash) {
                if (Hash::check(Str::upper($recoveryCode), $hash)) {
                    unset($hashes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }
        }

        return false;
    }
}
