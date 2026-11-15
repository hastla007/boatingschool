<?php

/**
 * Einstellungen (WooCommerce → Einstellungen → Bootsschule).
 *
 * Die Geheimnisse können statt in der Datenbank auch in wp-config.php
 * stehen (empfohlen): BS_CONNECTOR_APP_URL, BS_CONNECTOR_KEY_ID,
 * BS_CONNECTOR_SECRET, BS_CONNECTOR_INBOUND_KEY_ID, BS_CONNECTOR_INBOUND_SECRET.
 * Solche Felder sind im Formular gesperrt.
 *
 * Nur für Administratoren (manage_options): WooCommerce zeigt seine
 * Einstellungen schon Shop-Managern (manage_woocommerce) -- die dürften sonst
 * die Plattform-Adresse umbiegen und Lieferaufträge samt Geheimnis an einen
 * fremden Server schicken.
 */
defined('ABSPATH') || exit;

final class BS_Settings
{
    const OPTION = 'bs_connector_settings';

    /** Berechtigung für Tab, Anzeige und Speichern. */
    const CAPABILITY = 'manage_options';

    /** Felder, die eine wp-config.php-Konstante festlegen kann. */
    const CONSTANTS = [
        'app_url' => 'BS_CONNECTOR_APP_URL',
        'key_id' => 'BS_CONNECTOR_KEY_ID',
        'secret' => 'BS_CONNECTOR_SECRET',
        'inbound_key_id' => 'BS_CONNECTOR_INBOUND_KEY_ID',
        'inbound_secret' => 'BS_CONNECTOR_INBOUND_SECRET',
    ];

    /** @return array{app_url: string, key_id: string, secret: string, inbound_key_id: string, inbound_secret: string, alert_email: string, vat_meta_key: string, blocked_gateways: string} */
    public static function get(): array
    {
        $stored = (array) get_option(self::OPTION, []);
        $value = static function (string $constant, string $key, string $default = '') use ($stored): string {
            if (defined($constant)) {
                return (string) constant($constant);
            }

            return isset($stored[$key]) ? (string) $stored[$key] : $default;
        };

        return [
            'app_url' => $value('BS_CONNECTOR_APP_URL', 'app_url'),
            'key_id' => $value('BS_CONNECTOR_KEY_ID', 'key_id', 'shop'),
            'secret' => $value('BS_CONNECTOR_SECRET', 'secret'),
            'inbound_key_id' => $value('BS_CONNECTOR_INBOUND_KEY_ID', 'inbound_key_id', 'app'),
            'inbound_secret' => $value('BS_CONNECTOR_INBOUND_SECRET', 'inbound_secret'),
            'alert_email' => isset($stored['alert_email']) && $stored['alert_email'] !== '' ? (string) $stored['alert_email'] : (string) get_option('admin_email'),
            'vat_meta_key' => isset($stored['vat_meta_key']) && $stored['vat_meta_key'] !== '' ? (string) $stored['vat_meta_key'] : 'billing_vat_id',
            // Leer gespeichert = keine Zahlart sperren (bewusste Entscheidung des Shops).
            'blocked_gateways' => isset($stored['blocked_gateways']) ? (string) $stored['blocked_gateways'] : 'cod',
        ];
    }

    /**
     * Zahlarten (Gateway-IDs) ohne bestätigten Zahlungseingang: für Bootsschul-Codes
     * an der Kasse ausgeblendet, Bestellungen damit werden erst nach Zahlung zugestellt.
     *
     * @return string[]
     */
    public static function blocked_gateways(): array
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', self::get()['blocked_gateways']))));

        return array_values(array_filter(array_map('strval', (array) apply_filters('bs_connector_blocked_gateways', $ids))));
    }

    public static function init(): void
    {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_tab'], 60);
        add_action('woocommerce_settings_tabs_bs_connector', [__CLASS__, 'render']);
        add_action('woocommerce_update_options_bs_connector', [__CLASS__, 'save']);
    }

    public static function can_manage(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /** In wp-config.php festgelegt: im Formular gesperrt, beim Speichern ignoriert. */
    public static function is_locked(string $key): bool
    {
        return isset(self::CONSTANTS[$key]) && defined(self::CONSTANTS[$key]);
    }

    public static function add_tab(array $tabs): array
    {
        if (self::can_manage()) {
            $tabs['bs_connector'] = __('Bootsschule', 'bs-connector');
        }

        return $tabs;
    }

    private static function fields(): array
    {
        return [
            ['title' => __('Anbindung an die Bootsschul-Plattform', 'bs-connector'), 'type' => 'title', 'id' => 'bs_connector_section', 'desc' => __('Gekaufte Codes werden nach der Zahlung dem Codebestand der verknüpften Bootsschule gutgeschrieben. Werte in wp-config.php haben Vorrang.', 'bs-connector')],
            ['title' => __('Adresse der Plattform', 'bs-connector'), 'id' => self::OPTION.'[app_url]', 'type' => 'url', 'placeholder' => 'https://app.example.de'],
            ['title' => __('Schlüssel-ID (Shop → App)', 'bs-connector'), 'id' => self::OPTION.'[key_id]', 'type' => 'text', 'default' => 'shop'],
            ['title' => __('Geheimnis (Shop → App)', 'bs-connector'), 'id' => self::OPTION.'[secret]', 'type' => 'password', 'value' => '', 'placeholder' => self::secret_placeholder('secret')],
            ['title' => __('Schlüssel-ID (App → Shop)', 'bs-connector'), 'id' => self::OPTION.'[inbound_key_id]', 'type' => 'text', 'default' => 'app'],
            ['title' => __('Geheimnis (App → Shop)', 'bs-connector'), 'id' => self::OPTION.'[inbound_secret]', 'type' => 'password', 'value' => '', 'placeholder' => self::secret_placeholder('inbound_secret')],
            ['title' => __('Alarm-Adresse', 'bs-connector'), 'id' => self::OPTION.'[alert_email]', 'type' => 'email', 'desc' => __('Erhält eine Mail, wenn eine Zustellung endgültig fehlschlägt. Leer = Admin-Adresse von WordPress.', 'bs-connector')],
            ['title' => __('Meta-Feld der USt-ID', 'bs-connector'), 'id' => self::OPTION.'[vat_meta_key]', 'type' => 'text', 'default' => 'billing_vat_id'],
            ['title' => __('Gesperrte Zahlarten', 'bs-connector'), 'id' => self::OPTION.'[blocked_gateways]', 'type' => 'text', 'default' => 'cod', 'desc' => __('IDs der Zahlarten ohne bestätigten Zahlungseingang, kommagetrennt (z. B. cod für Nachnahme). Sie werden für Bootsschul-Codes an der Kasse ausgeblendet; Bestellungen damit werden erst nach Zahlungseingang zugestellt.', 'bs-connector')],
            ['type' => 'sectionend', 'id' => 'bs_connector_section'],
        ];
    }

    /**
     * Felder ohne die per Konstante festgelegten: diese stehen nur als Hinweis
     * im Formular (ohne Eingabefeld, Geheimnisse ohne Wert) und werden nie
     * gespeichert.
     */
    private static function editable_fields(): array
    {
        $fields = [];
        foreach (self::fields() as $field) {
            $key = preg_match('/\[(\w+)\]$/', (string) ($field['id'] ?? ''), $m) ? $m[1] : null;
            if ($key !== null && self::is_locked($key)) {
                continue;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /** Hinweis auf Werte aus wp-config.php (ohne Geheimnisse anzuzeigen). */
    private static function locked_notice(): array
    {
        $locked = array_values(array_filter(array_keys(self::CONSTANTS), [__CLASS__, 'is_locked']));
        if ($locked === []) {
            return [];
        }
        $names = array_map(static fn (string $key): string => self::CONSTANTS[$key], $locked);

        return [[
            'title' => __('In wp-config.php festgelegt', 'bs-connector'),
            'type' => 'title',
            'id' => 'bs_connector_locked',
            'desc' => sprintf(
                /* translators: %s: Namen der Konstanten */
                __('Diese Werte sind hier nicht änderbar: %s', 'bs-connector'),
                implode(', ', $names)
            ),
        ], ['type' => 'sectionend', 'id' => 'bs_connector_locked']];
    }

    public static function render(): void
    {
        if (! self::can_manage()) {
            return;
        }
        woocommerce_admin_fields(array_merge(self::locked_notice(), self::editable_fields()));
    }

    /** Gespeicherte Geheimnisse nie zurück ins Formular schreiben. */
    private static function secret_placeholder(string $key): string
    {
        $stored = (array) get_option(self::OPTION, []);

        return ! empty($stored[$key]) ? __('gespeichert – leer lassen, um es zu behalten', 'bs-connector') : '';
    }

    public static function save(): void
    {
        if (! self::can_manage()) {
            return;
        }
        $stored = (array) get_option(self::OPTION, []);
        // Per Konstante festgelegt: nie aus dem Formular übernehmen (der
        // gespeicherte Wert bleibt, wie er war).
        foreach (array_keys(self::CONSTANTS) as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
            if (self::is_locked($key) && isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION])) {
                unset($_POST[self::OPTION][$key]);
            }
        }
        // Leeres Geheimnis-Feld = unverändert lassen.
        foreach (['secret', 'inbound_secret'] as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
            if (isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION]) && ($_POST[self::OPTION][$key] ?? '') === '' && ! empty($stored[$key])) {
                $_POST[self::OPTION][$key] = wp_slash($stored[$key]); // WooCommerce entfernt die Maskierung beim Speichern.
            }
        }
        woocommerce_update_options(self::editable_fields());
        delete_transient('bs_connector_catalog');
    }
}
