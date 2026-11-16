<?php

/**
 * Protokoll über den WooCommerce-Logger (WooCommerce → Status → Protokolle,
 * Quelle „bs-connector“): API-Fehler, Zustellungen, Sperren.
 *
 * Nie Codewerte oder Geheimnisse: redact() entfernt entsprechende Felder aus
 * dem Kontext, bevor geschrieben wird (ohne WordPress testbar).
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Log
{
    const SOURCE = 'bs-connector';

    /** Feldnamen, deren Werte nie ins Protokoll gehören (Teilwort, ohne Groß-/Kleinschreibung). */
    const SECRET_KEYS = ['link_code', 'coupon', 'secret', 'token', 'password', 'signature', 'nonce', 'authorization', 'x-bs-'];

    /** Diese Felder heißen „*_code“, sind aber Kennungen des Katalogs, keine Codewerte. */
    const ALLOWED_KEYS = ['course_code', 'product_code', 'option_product_codes', 'error_code', 'http_code'];

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * Kontext ohne Codewerte und Geheimnisse (rekursiv). Ein Feld „code“ oder
     * „codes“ wird immer entfernt -- auch wenn es nur ein Fehlercode wäre.
     */
    public static function redact(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);
            if (! in_array($name, self::ALLOWED_KEYS, true)) {
                if ($name === 'code' || $name === 'codes' || substr($name, -5) === '_code' || substr($name, -6) === '_codes') {
                    continue;
                }
                foreach (self::SECRET_KEYS as $secret) {
                    if (strpos($name, $secret) !== false) {
                        continue 2;
                    }
                }
            }
            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->log($level, $message, ['source' => self::SOURCE] + self::redact($context));
    }
}
