<?php

/**
 * HMAC-SHA256-Signatur der Server-zu-Server-Aufrufe zwischen Shop und App.
 * Muss exakt App\Commerce\Signature in der Laravel-App entsprechen
 * (ein Test der App prüft das gegen diese Datei).
 *
 * Signiert wird: METHODE \n PFAD \n ZEITSTEMPEL \n NONCE \n SHA256(BODY)
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Signature
{
    const HEADER_KEY = 'X-BS-Key';

    const HEADER_TIMESTAMP = 'X-BS-Timestamp';

    const HEADER_NONCE = 'X-BS-Nonce';

    const HEADER_SIGNATURE = 'X-BS-Signature';

    /** Zulässige Uhrzeit-Abweichung in Sekunden. */
    const TOLERANCE = 300;

    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    /** @return array<string, string> */
    public static function headers(string $key_id, string $secret, string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            self::HEADER_KEY => $key_id,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }

    /**
     * Eingehende Anfrage der App prüfen (Zeitfenster und Signatur; die
     * Einmaligkeit der Nonce prüft der Aufrufer).
     */
    public static function verify(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature, ?int $now = null): bool
    {
        if ($secret === '' || ! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9\-]{16,64}$/', $nonce) || $signature === '') {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(self::sign($secret, $method, $path, $timestamp, $nonce, $body), strtolower($signature));
    }
}
