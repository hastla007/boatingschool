<?php

/**
 * Einstellungen (WooCommerce → Einstellungen → Bootsschule).
 *
 * Die Geheimnisse gehören in wp-config.php: BS_CONNECTOR_APP_URL,
 * BS_CONNECTOR_KEY_ID, BS_CONNECTOR_SECRET, BS_CONNECTOR_INBOUND_KEY_ID,
 * BS_CONNECTOR_INBOUND_SECRET. Ist eine Geheimnis-Konstante gesetzt, blendet
 * die Einstellungsseite das Feld aus. Liegen Geheimnisse (noch) in der
 * Datenbank, zeigt das Backend einen dauerhaften Hinweis; mit der Einstellung
 * „Geheimnisse aus der Datenbank erlauben“ = nein bzw. dem Filter
 * bs_connector_allow_db_secrets (false) werden sie ignoriert.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Settings
{
    const OPTION = 'bs_connector_settings';

    /** Geheimnisse: Einstellungs-Schlüssel => Konstante in wp-config.php. */
    const SECRETS = ['secret' => 'BS_CONNECTOR_SECRET', 'inbound_secret' => 'BS_CONNECTOR_INBOUND_SECRET'];

    /** @return array{app_url: string, key_id: string, secret: string, inbound_key_id: string, inbound_secret: string, alert_email: string, vat_meta_key: string, blocked_gateways: string, vat_check: string, vies_requester: string} */
    public static function get(): array
    {
        $stored = (array) get_option(self::OPTION, []);
        $value = static function (string $constant, string $key, string $default = '') use ($stored): string {
            if (defined($constant)) {
                return (string) constant($constant);
            }

            return isset($stored[$key]) ? (string) $stored[$key] : $default;
        };
        $allow_db = self::allow_db_secrets($stored);
        $secret = static function (string $key) use ($stored, $allow_db): string {
            $constant = self::SECRETS[$key];

            return self::resolve_secret(defined($constant) ? (string) constant($constant) : null, (string) ($stored[$key] ?? ''), $allow_db);
        };

        return [
            'app_url' => $value('BS_CONNECTOR_APP_URL', 'app_url'),
            'key_id' => $value('BS_CONNECTOR_KEY_ID', 'key_id', 'shop'),
            'secret' => $secret('secret'),
            'inbound_key_id' => $value('BS_CONNECTOR_INBOUND_KEY_ID', 'inbound_key_id', 'app'),
            'inbound_secret' => $secret('inbound_secret'),
            'alert_email' => isset($stored['alert_email']) && $stored['alert_email'] !== '' ? (string) $stored['alert_email'] : (string) get_option('admin_email'),
            'vat_meta_key' => isset($stored['vat_meta_key']) && $stored['vat_meta_key'] !== '' ? (string) $stored['vat_meta_key'] : 'billing_vat_id',
            // Leer gespeichert = keine Zahlart sperren (bewusste Entscheidung des Shops).
            'blocked_gateways' => isset($stored['blocked_gateways']) ? (string) $stored['blocked_gateways'] : 'cod',
            'vat_check' => isset($stored['vat_check']) && $stored['vat_check'] === 'no' ? 'no' : 'yes',
            'vies_requester' => $value('BS_CONNECTOR_VIES_REQUESTER', 'vies_requester'),
        ];
    }

    /**
     * Wirksames Geheimnis (ohne WordPress testbar): die Konstante aus
     * wp-config.php hat immer Vorrang; ein in der Datenbank gespeichertes
     * Geheimnis gilt nur, solange $allow_db (Einstellung bzw. Filter
     * bs_connector_allow_db_secrets) es erlaubt.
     */
    public static function resolve_secret(?string $constant, string $stored, bool $allow_db): string
    {
        if ($constant !== null) {
            return $constant;
        }

        return $allow_db ? $stored : '';
    }

    /**
     * Dürfen Geheimnisse aus der Datenbank verwendet werden? Einstellung
     * „Geheimnisse aus der Datenbank erlauben“ (Standard: ja, kompatibel zu
     * älteren Installationen), überschreibbar mit dem Filter
     * bs_connector_allow_db_secrets.
     */
    public static function allow_db_secrets(?array $stored = null): bool
    {
        $stored ??= (array) get_option(self::OPTION, []);
        $default = ! isset($stored['allow_db_secrets']) || $stored['allow_db_secrets'] !== 'no';

        return (bool) apply_filters('bs_connector_allow_db_secrets', $default);
    }

    /**
     * Welche Geheimnisse liegen (nicht leer) in der Datenbank?
     *
     * @return string[] Schlüssel aus SECRETS.
     */
    public static function stored_secrets(): array
    {
        $stored = (array) get_option(self::OPTION, []);

        return array_values(array_filter(array_keys(self::SECRETS), static fn ($key) => (string) ($stored[$key] ?? '') !== ''));
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
        add_action('admin_notices', [__CLASS__, 'secrets_notice']);
    }

    public static function add_tab(array $tabs): array
    {
        $tabs['bs_connector'] = __('Bootsschule', 'bs-connector');

        return $tabs;
    }

    private static function fields(): array
    {
        $fields = [
            ['title' => __('Anbindung an die Bootsschul-Plattform', 'bs-connector'), 'type' => 'title', 'id' => 'bs_connector_section', 'desc' => __('Gekaufte Codes werden nach der Zahlung dem Codebestand der verknüpften Bootsschule gutgeschrieben. Werte in wp-config.php haben Vorrang.', 'bs-connector')],
            ['title' => __('Adresse der Plattform', 'bs-connector'), 'id' => self::OPTION.'[app_url]', 'type' => 'url', 'placeholder' => 'https://app.example.de'],
            ['title' => __('Schlüssel-ID (Shop → App)', 'bs-connector'), 'id' => self::OPTION.'[key_id]', 'type' => 'text', 'default' => 'shop'],
            self::secret_field('secret', __('Geheimnis (Shop → App)', 'bs-connector')),
            ['title' => __('Schlüssel-ID (App → Shop)', 'bs-connector'), 'id' => self::OPTION.'[inbound_key_id]', 'type' => 'text', 'default' => 'app'],
            self::secret_field('inbound_secret', __('Geheimnis (App → Shop)', 'bs-connector')),
            ['title' => __('Geheimnisse aus der Datenbank erlauben', 'bs-connector'), 'id' => self::OPTION.'[allow_db_secrets]', 'type' => 'checkbox', 'default' => 'yes', 'desc' => __('Empfohlen: aus. Dann gelten nur die Konstanten BS_CONNECTOR_SECRET und BS_CONNECTOR_INBOUND_SECRET aus wp-config.php; in der Datenbank gespeicherte Geheimnisse werden ignoriert (Filter bs_connector_allow_db_secrets).', 'bs-connector')],
            ['title' => __('Alarm-Adresse', 'bs-connector'), 'id' => self::OPTION.'[alert_email]', 'type' => 'email', 'desc' => __('Erhält eine Mail, wenn eine Zustellung endgültig fehlschlägt. Leer = Admin-Adresse von WordPress.', 'bs-connector')],
            ['title' => __('Meta-Feld der USt-ID', 'bs-connector'), 'id' => self::OPTION.'[vat_meta_key]', 'type' => 'text', 'default' => 'billing_vat_id'],
            ['title' => __('USt-IdNr. per VIES prüfen', 'bs-connector'), 'id' => self::OPTION.'[vat_check]', 'type' => 'checkbox', 'default' => 'yes', 'desc' => __('Prüft Format und Gültigkeit der USt-IdNr. beim EU-Dienst VIES (Registrierung, Kasse, Rechnungsadresse). Ist VIES nicht erreichbar, wird die Nummer angenommen und später erneut geprüft.', 'bs-connector')],
            ['title' => __('Eigene USt-IdNr. (für VIES)', 'bs-connector'), 'id' => self::OPTION.'[vies_requester]', 'type' => 'text', 'placeholder' => 'DE123456789', 'desc' => __('Optional. Mit der eigenen Nummer liefert VIES eine Abfragenummer als Nachweis der Prüfung.', 'bs-connector')],
            ['title' => __('Gesperrte Zahlarten', 'bs-connector'), 'id' => self::OPTION.'[blocked_gateways]', 'type' => 'text', 'default' => 'cod', 'desc' => __('IDs der Zahlarten ohne bestätigten Zahlungseingang, kommagetrennt (z. B. cod für Nachnahme). Sie werden für Bootsschul-Codes an der Kasse ausgeblendet; Bestellungen damit werden erst nach Zahlungseingang zugestellt.', 'bs-connector')],
            ['type' => 'sectionend', 'id' => 'bs_connector_section'],
        ];

        return array_values(array_filter($fields));
    }

    /** Geheimnis-Feld; null (ausgeblendet), wenn die Konstante in wp-config.php gesetzt ist. */
    private static function secret_field(string $key, string $title): ?array
    {
        if (defined(self::SECRETS[$key])) {
            return null;
        }

        return ['title' => $title, 'id' => self::OPTION.'['.$key.']', 'type' => 'password', 'value' => '', 'placeholder' => self::secret_placeholder($key), 'desc' => sprintf( /* translators: %s: Name der Konstante */ __('Empfohlen: stattdessen %s in wp-config.php setzen.', 'bs-connector'), self::SECRETS[$key])];
    }

    public static function render(): void
    {
        woocommerce_admin_fields(self::fields());
        if (current_user_can('manage_woocommerce')) {
            // Prüft Adresse, Schlüssel und Geheimnis mit GET /catalog (gespeicherte Werte).
            echo '<p><a class="button" href="'.esc_url(BS_Admin::connection_test_url()).'">'.esc_html__('Verbindung testen', 'bs-connector').'</a> ';
            echo '<span class="description">'.esc_html__('Verwendet die gespeicherten Einstellungen – vorher speichern.', 'bs-connector').'</span></p>';
        }
    }

    /** Gespeicherte Geheimnisse nie zurück ins Formular schreiben. */
    private static function secret_placeholder(string $key): string
    {
        $stored = (array) get_option(self::OPTION, []);

        return ! empty($stored[$key]) ? __('gespeichert – leer lassen, um es zu behalten', 'bs-connector') : '';
    }

    public static function save(): void
    {
        // Leeres Geheimnis-Feld = unverändert lassen.
        $stored = (array) get_option(self::OPTION, []);
        foreach (array_keys(self::SECRETS) as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
            if (isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION]) && ($_POST[self::OPTION][$key] ?? '') === '' && ! empty($stored[$key])) {
                $_POST[self::OPTION][$key] = wp_slash($stored[$key]); // WooCommerce entfernt die Maskierung beim Speichern.
            }
        }
        woocommerce_update_options(self::fields());
        // Steht das Geheimnis in wp-config.php, wird die Kopie in der Datenbank
        // nie verwendet: beim Speichern entfernen.
        $saved = (array) get_option(self::OPTION, []);
        $changed = false;
        foreach (self::SECRETS as $key => $constant) {
            if (defined($constant) && isset($saved[$key])) {
                unset($saved[$key]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::OPTION, $saved);
        }
        delete_transient('bs_connector_catalog');
    }

    /**
     * Dauerhafter Hinweis im Backend, solange Geheimnisse in der Datenbank
     * liegen (nicht ausblendbar, nur für manage_woocommerce).
     */
    public static function secrets_notice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $stored = self::stored_secrets();
        if ($stored === []) {
            return;
        }
        $constants = implode(', ', array_map(static fn ($key) => self::SECRETS[$key], $stored));
        $all_defined = array_filter($stored, static fn ($key) => ! defined(self::SECRETS[$key])) === [];
        if ($all_defined) {
            $state = __('Sie werden nicht verwendet, weil die Konstanten in wp-config.php gesetzt sind. Speichern Sie die Einstellungen unter WooCommerce → Einstellungen → Bootsschule einmal, um sie aus der Datenbank zu entfernen.', 'bs-connector');
        } elseif (! self::allow_db_secrets()) {
            $state = __('Sie werden ignoriert, weil Geheimnisse aus der Datenbank nicht erlaubt sind – die Anbindung funktioniert erst mit den Konstanten in wp-config.php.', 'bs-connector');
        } else {
            $state = __('Empfohlen: in wp-config.php verschieben und danach „Geheimnisse aus der Datenbank erlauben“ ausschalten.', 'bs-connector');
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Bootsschul-Plattform Connector:', 'bs-connector').'</strong> ';
        echo esc_html(sprintf( /* translators: %s: Namen der Konstanten */ __('Geheimnisse der Anbindung liegen in der Datenbank (wp_options). Sicherer ist wp-config.php: %s.', 'bs-connector'), $constants)).' ';
        echo esc_html($state).'</p></div>';
    }
}
