<?php

namespace App\Integrations\FormCaptain;

/**
 * HMAC-SHA256-Signatur des Lead-Eingangs (Vertrag form-captain-lead/1, A.2).
 * Bewusst NICHT App\Commerce\Signature: anderer Kanon, andere Header, eigener
 * Schlüssel. Dieselbe Berechnung steht im WordPress-Connector
 * (integrations/boatingschool-form-connector/src/Signature.php); ein Test
 * prüft, dass beide übereinstimmen.
 *
 * Signiert wird: "v1" \n ZEITSTEMPEL \n NONCE \n METHODE \n PFAD \n SHA256(BODY)
 */
final class LeadSignature
{
    public const HEADER_KEY = 'X-FC-Key-Id';

    public const HEADER_TIMESTAMP = 'X-FC-Timestamp';

    public const HEADER_NONCE = 'X-FC-Nonce';

    public const HEADER_SIGNATURE = 'X-FC-Signature';

    public const VERSION = 'v1';

    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return self::VERSION."\n".$timestamp."\n".$nonce."\n".strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".hash('sha256', $body);
    }

    /** Wert des Headers X-FC-Signature: "v1=" + hex(HMAC). */
    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return self::VERSION.'='.hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    /** @return array<string, string> Header für eine Anfrage (Tests, Werkzeuge). */
    public static function headers(string $keyId, string $secret, string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        return [
            self::HEADER_KEY => $keyId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }
}
