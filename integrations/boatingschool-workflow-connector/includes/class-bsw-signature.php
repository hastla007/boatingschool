<?php

/**
 * HMAC-SHA256 des Workflow-Vertrags zwischen Plattform-App und diesem Plugin.
 * Muss exakt App\Workflow\WorkflowSignature der Laravel-App entsprechen (ein
 * Test der App prüft das gegen diese Datei).
 *
 * Signiert wird "ZEITSTEMPEL.NONCE.ROHER BODY" -- dieselbe Form wie
 * WorkflowCaptain\Security\InboundGuard::sign() in Workflow Captain. Methode
 * und Pfad sind nicht signiert; jeder Body nennt seinen Zweck ("op").
 *
 * Eigene Header (X-BSW-…) und eigene Schlüssel, getrennt von der
 * Commerce-Anbindung des WooCommerce-Connectors (X-BS-…).
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Signature
{
    const HEADER_KEY = 'X-BSW-Key';

    const HEADER_TIMESTAMP = 'X-BSW-Timestamp';

    const HEADER_NONCE = 'X-BSW-Nonce';

    const HEADER_SIGNATURE = 'X-BSW-Signature';

    /** Zulässige Uhrzeit-Abweichung in Sekunden. */
    const TOLERANCE = 300;

    const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public static function sign(string $secret, $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
    }

    /** @return array<string, string> */
    public static function headers(string $key_id, string $secret, string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(16));

        return [
            self::HEADER_KEY => $key_id,
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
