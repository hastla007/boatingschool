<?php

/**
 * Einstellungen nur aus wp-config.php (keine Geheimnisse in der Datenbank):
 *
 *   BSW_CONNECTOR_APP_URL           https-Adresse der Plattform-App (z. B. https://lernen.example.de)
 *   BSW_CONNECTOR_KEY_ID            Schlüssel-ID für Aufrufe Plugin -> App (WORKFLOW_KEY_ID der App)
 *   BSW_CONNECTOR_SECRET            Geheimnis dazu (WORKFLOW_SECRET der App)
 *   BSW_CONNECTOR_INBOUND_KEY_ID    Schlüssel-ID der App für Ereignisse App -> Plugin (WORKFLOW_OUTBOUND_KEY_ID)
 *   BSW_CONNECTOR_INBOUND_SECRET    Geheimnis dazu (WORKFLOW_OUTBOUND_SECRET)
 *   BSW_CONNECTOR_INBOUND_KEY_ID_NEXT / BSW_CONNECTOR_INBOUND_SECRET_NEXT
 *                                   zweites Paar, nur während eines Schlüsselwechsels
 *
 * Alle Schlüssel sind eigene Workflow-Schlüssel, nie die des
 * WooCommerce-Connectors (BS_CONNECTOR_*): ein gleiches Geheimnis wird
 * nicht angenommen.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Settings
{
    const MIN_SECRET_LENGTH = 16;

    public static function get(string $name): string
    {
        $constant = 'BSW_CONNECTOR_'.strtoupper($name);

        return defined($constant) ? trim((string) constant($constant)) : '';
    }

    public static function app_url(): string
    {
        $url = rtrim(self::get('app_url'), '/');

        return strpos($url, 'https://') === 0 || (defined('BSW_CONNECTOR_ALLOW_HTTP') && BSW_CONNECTOR_ALLOW_HTTP) ? $url : '';
    }

    /** Aufrufe Plugin -> App. @return array{key_id:string, secret:string}|null */
    public static function outbound(): ?array
    {
        $key = self::get('key_id');
        $secret = self::get('secret');

        return $key !== '' && self::usable($secret) ? ['key_id' => $key, 'secret' => $secret] : null;
    }

    /** Ereignisse App -> Plugin: Schlüssel-ID => Geheimnis (aktuelles und ggf. nächstes Paar). @return array<string, string> */
    public static function inbound_keys(): array
    {
        $keys = [];
        foreach (['', '_next'] as $suffix) {
            $id = self::get('inbound_key_id'.$suffix);
            $secret = self::get('inbound_secret'.$suffix);
            if ($id !== '' && self::usable($secret)) {
                $keys[$id] = $secret;
            }
        }

        return $keys;
    }

    /** Lang genug und nicht das Geheimnis des WooCommerce-Connectors. */
    private static function usable(string $secret): bool
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return false;
        }
        foreach (['BS_CONNECTOR_SECRET', 'BS_CONNECTOR_INBOUND_SECRET'] as $commerce) {
            if (defined($commerce) && hash_equals((string) constant($commerce), $secret)) {
                return false;
            }
        }

        return true;
    }

    /** Für die Statusseite: was ist eingerichtet (nie Werte). @return array<string, bool> */
    public static function overview(): array
    {
        return [
            'app_url' => self::app_url() !== '',
            'outbound_key' => self::outbound() !== null,
            'inbound_key' => self::inbound_keys() !== [],
            'inbound_rotation' => count(self::inbound_keys()) > 1,
        ];
    }
}
