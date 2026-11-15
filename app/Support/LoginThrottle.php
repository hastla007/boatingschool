<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Konto-bezogene Anmeldegrenzen, gemeinsam für Web-Login und Mobile-API
 * (die E-Mail+IP-Grenzen bleiben in den Aufrufern).
 *
 * Audit SEC-W-1: bisher sperrten 20 Fehlversuche je Konto (IP-unabhängig)
 * die Anmeldung auch mit korrektem Passwort -- jeder konnte so ein
 * beliebiges Konto (auch Superadmin) dauerhaft aussperren. Jetzt:
 * - Fehlversuche zählen je Konto UND Netz (/24 bzw. /48). Ab 20 in 15 Min.
 *   wird aus diesem Netz gar nicht mehr geprüft (auch kein richtiges
 *   Passwort) -- das trifft nur das Netz des Angreifers.
 * - Zusätzlich zählt ein Gesamtzähler je Konto. Ist er voll (verteilter
 *   Angriff), sinkt die Grenze je Netz auf 3: jedes weitere Netz darf nur
 *   noch wenige Male raten, wer aus einem sauberen Netz das richtige
 *   Passwort eingibt, kommt weiter durch (danach greift ggf. 2FA).
 */
class LoginThrottle
{
    /** Fehlversuche je Konto und Netz in 15 Minuten. */
    public const NETWORK_ATTEMPTS = 20;

    /** Fehlversuche je Konto über alle Netze in 15 Minuten. */
    public const ACCOUNT_ATTEMPTS = 20;

    /** Grenze je Netz, solange das Konto insgesamt über der Grenze liegt. */
    public const NETWORK_ATTEMPTS_UNDER_ATTACK = 3;

    public const DECAY_SECONDS = 900;

    /** Sekunden bis zum nächsten erlaubten Versuch, oder null. */
    public static function blockedFor(string $email, ?string $ip): ?int
    {
        $networkKey = self::networkKey($email, $ip);
        $max = RateLimiter::tooManyAttempts(self::accountKey($email), self::ACCOUNT_ATTEMPTS)
            ? self::NETWORK_ATTEMPTS_UNDER_ATTACK
            : self::NETWORK_ATTEMPTS;

        return RateLimiter::tooManyAttempts($networkKey, $max) ? max(1, RateLimiter::availableIn($networkKey)) : null;
    }

    public static function failed(string $email, ?string $ip): void
    {
        RateLimiter::hit(self::accountKey($email), self::DECAY_SECONDS);
        RateLimiter::hit(self::networkKey($email, $ip), self::DECAY_SECONDS);
    }

    /** Nach erfolgreicher Anmeldung oder Passwort-Reset. */
    public static function clear(string $email, ?string $ip): void
    {
        RateLimiter::clear(self::accountKey($email));
        RateLimiter::clear(self::networkKey($email, $ip));
    }

    public static function accountKey(string $email): string
    {
        return 'login-account:'.self::normalize($email);
    }

    public static function networkKey(string $email, ?string $ip): string
    {
        return 'login-account:'.self::normalize($email).'|'.self::network($ip);
    }

    /** IPv4 auf /24, IPv6 auf /48 gekürzt. */
    public static function network(?string $ip): string
    {
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return inet_ntop(substr(inet_pton($ip), 0, 6).str_repeat("\0", 10)).'/48';
        }

        return 'unbekannt';
    }

    private static function normalize(string $email): string
    {
        return Str::transliterate(Str::lower(trim($email)));
    }
}
