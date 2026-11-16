<?php

/**
 * HMAC-SHA256-Signatur des Lead-Eingangs (Vertrag form-captain-lead/1, A.2).
 *
 * Dieselbe Berechnung steht in der Plattform (app/Integrations/FormCaptain/LeadSignature.php);
 * tests/Unit/FormConnectorContractTest.php prüft, dass beide übereinstimmen.
 *
 * Signiert wird: "v1" \n ZEITSTEMPEL \n NONCE \n METHODE \n PFAD \n SHA256(BODY)
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Signature
{
    public const VERSION = 'v1';

    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return self::VERSION."\n".$timestamp."\n".$nonce."\n".strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return self::VERSION.'='.hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    /**
     * Header einer signierten Anfrage; je Anfrage neue Nonce (32 Hex-Zeichen).
     *
     * @return array<string,string>
     */
    public static function headers(string $key_id, string $secret, string $method, string $path, string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-FC-Key-Id' => $key_id,
            'X-FC-Timestamp' => $timestamp,
            'X-FC-Nonce' => $nonce,
            'X-FC-Signature' => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }
}
