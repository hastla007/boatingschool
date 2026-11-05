<?php

namespace App\Support;

/**
 * Zeitbasierte Einmal-Codes (TOTP, RFC 6238) für die Zwei-Faktor-Anmeldung:
 * HMAC-SHA1, 30-Sekunden-Schritte, 6 Ziffern -- das Format, das gängige
 * Authenticator-Apps erwarten. Das Geheimnis ist Base32-kodiert.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const STEP = 30;

    private const DIGITS = 6;

    /** Neues Geheimnis mit 160 Bit (32 Base32-Zeichen). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Code für einen Zeitpunkt (Unix-Sekunden). */
    public static function at(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::STEP);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binaryCounter, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft einen Code mit ±1 Schritt Toleranz (Uhren gehen leicht falsch).
     * Liefert den Zeitschritt des Treffers (gegen Wiederverwendung) oder null.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, ?int $notAfterStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $timestamp ??= time();
        foreach ([0, -1, 1] as $drift) {
            $step = intdiv($timestamp, self::STEP) + $drift;
            if ($notAfterStep !== null && $step <= $notAfterStep) {
                continue;
            }
            if (hash_equals(self::at($secret, $step * self::STEP), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** otpauth://-Adresse für den QR-Code der Authenticator-App. */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
