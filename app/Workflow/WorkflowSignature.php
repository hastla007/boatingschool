<?php

namespace App\Workflow;

/**
 * HMAC-SHA256 des Workflow-Vertrags (WF-CUST1-05), in beide Richtungen
 * gleich: signiert wird "ZEITSTEMPEL.NONCE.ROHER BODY" -- dieselbe Form wie
 * WorkflowCaptain\Security\InboundGuard::sign() im Kern von Workflow Captain.
 * Dieselbe Berechnung steht im Connector-Plugin
 * (integrations/boatingschool-workflow-connector/includes/class-bsw-signature.php);
 * ein Test prüft, dass beide übereinstimmen.
 *
 * Methode und Pfad sind nicht Teil der Signatur. Deshalb nennt jeder Body
 * seinen Zweck selbst ("op": handshake | command | event), und der Empfänger
 * lehnt einen Body ab, dessen Zweck nicht zum Endpunkt passt.
 *
 * Eigene Header (X-BSW-…), nicht die der Commerce-API (X-BS-…).
 */
final class WorkflowSignature
{
    public const HEADER_KEY = 'X-BSW-Key';

    public const HEADER_TIMESTAMP = 'X-BSW-Timestamp';

    public const HEADER_NONCE = 'X-BSW-Nonce';

    public const HEADER_SIGNATURE = 'X-BSW-Signature';

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public static function sign(string $secret, int|string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
    }

    /** @return array<string, string> Header für eine ausgehende Anfrage. */
    public static function headers(string $keyId, string $secret, string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(16));

        return [
            self::HEADER_KEY => $keyId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $timestamp, $nonce, $body),
        ];
    }

    public static function verify(string $secret, string $timestamp, string $nonce, string $body, string $signature): bool
    {
        return $secret !== '' && hash_equals(self::sign($secret, $timestamp, $nonce, $body), strtolower($signature));
    }
}
