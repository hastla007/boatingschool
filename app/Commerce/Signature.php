<?php

namespace App\Commerce;

/**
 * HMAC-SHA256-Signatur der Server-zu-Server-Aufrufe zwischen App und Shop.
 * Dieselbe Berechnung steht im Connector-Plugin
 * (integrations/woocommerce-connector/includes/class-bs-signature.php);
 * ein Test prüft, dass beide übereinstimmen.
 *
 * Signiert wird: METHODE \n PFAD \n ZEITSTEMPEL \n NONCE \n SHA256(BODY)
 */
class Signature
{
    public const HEADER_KEY = 'X-BS-Key';

    public const HEADER_TIMESTAMP = 'X-BS-Timestamp';

    public const HEADER_NONCE = 'X-BS-Nonce';

    public const HEADER_SIGNATURE = 'X-BS-Signature';

    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    /** @return array<string, string> Header für eine ausgehende Anfrage. */
    public static function headers(string $keyId, string $secret, string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            self::HEADER_KEY => $keyId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }
}
