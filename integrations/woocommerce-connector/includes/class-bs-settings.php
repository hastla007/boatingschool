<?php

/**
 * Einstellungen (WooCommerce → Einstellungen → Bootsschule).
 *
 * Die Geheimnisse gehören in wp-config.php: BS_CONNECTOR_APP_URL,
 * BS_CONNECTOR_KEY_ID, BS_CONNECTOR_SECRET, BS_CONNECTOR_INBOUND_KEY_ID,
 * BS_CONNECTOR_INBOUND_SECRET (optional BS_CONNECTOR_VIES_REQUESTER). Per
 * Konstante festgelegte Felder sind im Formular gesperrt: Sie erscheinen nur
 * als Hinweis (ohne Eingabefeld, Geheimnisse ohne Wert) und werden beim
 * Speichern ignoriert; eine Kopie eines solchen Geheimnisses in der Datenbank
 * wird beim Speichern entfernt. Geheimnisse aus der Datenbank gelten nur, wenn
 * „Geheimnisse aus der Datenbank erlauben“ ausdrücklich eingeschaltet ist
 * (Standard seit 1.4.0: aus) bzw. der Filter bs_connector_allow_db_secrets
 * true liefert; sonst zeigt das Formular keine Geheimnis-Felder, sondern einen
 * Hinweis auf die Konstanten, und speichert keine Geheimnisse. Liegen
 * Geheimnisse (noch) in der Datenbank, zeigt das Backend einen dauerhaften
 * Hinweis.
 *
 * Übergang (maybe_upgrade): Beim ersten Laden nach dem Update von einer
 * Version vor 1.4.0 wird die Einstellung für Installationen, die Geheimnisse
 * ohne Konstante in der Datenbank haben, einmalig ausdrücklich eingeschaltet
 * -- die Anbindung fällt nicht aus, der Hinweis empfiehlt den Umzug nach
 * wp-config.php.
 *
 * Nur für Administratoren (manage_options): WooCommerce zeigt seine
 * Einstellungen schon Shop-Managern (manage_woocommerce) -- die dürften sonst
 * die Plattform-Adresse umbiegen und Lieferaufträge samt Geheimnis an einen
 * fremden Server schicken. Das gilt auch für den Hinweis auf Geheimnisse in
 * der Datenbank und den Verbindungstest.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Settings
{
    const OPTION = 'bs_connector_settings';

    /** Berechtigung für Tab, Anzeige, Speichern, Hinweise und Verbindungstest. */
    const CAPABILITY = 'manage_options';

    /** Felder, die eine wp-config.php-Konstante festlegen kann. */
    const CONSTANTS = [
        'app_url' => 'BS_CONNECTOR_APP_URL',
        'key_id' => 'BS_CONNECTOR_KEY_ID',
        'secret' => 'BS_CONNECTOR_SECRET',
        'inbound_key_id' => 'BS_CONNECTOR_INBOUND_KEY_ID',
        'inbound_secret' => 'BS_CONNECTOR_INBOUND_SECRET',
        'vies_requester' => 'BS_CONNECTOR_VIES_REQUESTER',
    ];

    /** Geheimnisse: Einstellungs-Schlüssel => Konstante in wp-config.php. */
    const SECRETS = ['secret' => 'BS_CONNECTOR_SECRET', 'inbound_secret' => 'BS_CONNECTOR_INBOUND_SECRET'];

    /** Zuletzt geladene Plugin-Version (für Update-Schritte). */
    const VERSION_OPTION = 'bs_connector_version';

    /** Merker: Das Update hat „Geheimnisse aus der Datenbank erlauben“ eingeschaltet. */
    const KEPT_DB_SECRETS_OPTION = 'bs_connector_db_secrets_kept';

    /** Ab dieser Version sind Geheimnisse aus der Datenbank standardmäßig aus. */
    const DB_SECRETS_OFF_SINCE = '1.4.0';

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
     * Dürfen Geheimnisse aus der Datenbank verwendet werden? Nur wenn
     * „Geheimnisse aus der Datenbank erlauben“ ausdrücklich eingeschaltet ist
     * (Standard: nein), überschreibbar mit dem Filter
     * bs_connector_allow_db_secrets.
     */
    public static function allow_db_secrets(?array $stored = null): bool
    {
        $stored ??= (array) get_option(self::OPTION, []);
        $default = isset($stored['allow_db_secrets']) && $stored['allow_db_secrets'] === 'yes';

        return (bool) apply_filters('bs_connector_allow_db_secrets', $default);
    }

    /**
     * Update-Schritte beim Laden (plugins_loaded), versioniert über
     * VERSION_OPTION: laufen je Version nur einmal.
     */
    public static function maybe_upgrade(string $version): void
    {
        $installed = (string) get_option(self::VERSION_OPTION, '');
        if ($installed === $version) {
            return;
        }
        // Leer = Neuinstallation oder Update von einer Version ohne Merker (vor 1.4.0).
        if ($installed === '' || version_compare($installed, self::DB_SECRETS_OFF_SINCE, '<')) {
            self::upgrade_db_secrets_default();
        }
        update_option(self::VERSION_OPTION, $version);
    }

    /**
     * Übergang auf „Geheimnisse aus der Datenbank: standardmäßig aus“: Wer ein
     * Geheimnis nur in der Datenbank hat (ohne Konstante), bekommt die
     * Einstellung ausdrücklich eingeschaltet, damit die Anbindung weiterläuft,
     * und einen Hinweis. Alle anderen bekommen „nein“ -- auch ein früher
     * gespeichertes „ja“ (der alte Standard), das ohne solche Geheimnisse
     * nichts bewirkt. Ein ausdrückliches „nein“ bleibt.
     */
    private static function upgrade_db_secrets_default(): void
    {
        $stored = (array) get_option(self::OPTION, []);
        if (($stored['allow_db_secrets'] ?? '') === 'no') {
            return;
        }
        if (self::db_only_secrets($stored) !== []) {
            $stored['allow_db_secrets'] = 'yes';
            update_option(self::OPTION, $stored);
            update_option(self::KEPT_DB_SECRETS_OPTION, 'yes');

            return;
        }
        if (isset($stored['allow_db_secrets'])) {
            $stored['allow_db_secrets'] = 'no';
            update_option(self::OPTION, $stored);
        }
    }

    /**
     * Geheimnisse, die nur in der Datenbank stehen (nicht leer, ohne Konstante).
     *
     * @return string[] Schlüssel aus SECRETS.
     */
    private static function db_only_secrets(array $stored): array
    {
        return array_values(array_filter(array_keys(self::SECRETS), static fn ($key) => (string) ($stored[$key] ?? '') !== '' && ! defined(self::SECRETS[$key])));
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
        add_action('woocommerce_settings_tabs_bs_connector', [__CLASS__, 'render_connection_test'], 20);
        add_action('woocommerce_update_options_bs_connector', [__CLASS__, 'save']);
        add_action('admin_notices', [__CLASS__, 'secrets_notice']);
        add_action('admin_notices', [__CLASS__, 'company_field_notice']);
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
            self::secret_field('secret', __('Geheimnis (Shop → App)', 'bs-connector')),
            ['title' => __('Schlüssel-ID (App → Shop)', 'bs-connector'), 'id' => self::OPTION.'[inbound_key_id]', 'type' => 'text', 'default' => 'app'],
            self::secret_field('inbound_secret', __('Geheimnis (App → Shop)', 'bs-connector')),
            ['title' => __('Geheimnisse aus der Datenbank erlauben', 'bs-connector'), 'id' => self::OPTION.'[allow_db_secrets]', 'type' => 'checkbox', 'default' => 'no', 'desc' => __('Empfohlen: aus (Standard). Dann gelten nur die Konstanten BS_CONNECTOR_SECRET und BS_CONNECTOR_INBOUND_SECRET aus wp-config.php: Das Formular zeigt keine Geheimnis-Felder, in der Datenbank gespeicherte Geheimnisse werden ignoriert. Erst nach dem Einschalten und Speichern erscheinen die Felder (Filter bs_connector_allow_db_secrets).', 'bs-connector')],
            ['title' => __('Alarm-Adresse', 'bs-connector'), 'id' => self::OPTION.'[alert_email]', 'type' => 'email', 'desc' => __('Erhält eine Mail, wenn eine Zustellung endgültig fehlschlägt. Leer = Admin-Adresse von WordPress.', 'bs-connector')],
            ['title' => __('Meta-Feld der USt-ID', 'bs-connector'), 'id' => self::OPTION.'[vat_meta_key]', 'type' => 'text', 'default' => 'billing_vat_id'],
            ['title' => __('USt-IdNr. per VIES prüfen', 'bs-connector'), 'id' => self::OPTION.'[vat_check]', 'type' => 'checkbox', 'default' => 'yes', 'desc' => __('Prüft Format und Gültigkeit der USt-IdNr. beim EU-Dienst VIES (Registrierung, Kasse, Rechnungsadresse). Ist VIES nicht erreichbar, wird die Nummer angenommen und später erneut geprüft.', 'bs-connector')],
            ['title' => __('Eigene USt-IdNr. (für VIES)', 'bs-connector'), 'id' => self::OPTION.'[vies_requester]', 'type' => 'text', 'placeholder' => 'DE123456789', 'desc' => __('Optional. Mit der eigenen Nummer liefert VIES eine Abfragenummer als Nachweis der Prüfung.', 'bs-connector')],
            ['title' => __('Gesperrte Zahlarten', 'bs-connector'), 'id' => self::OPTION.'[blocked_gateways]', 'type' => 'text', 'default' => 'cod', 'desc' => __('IDs der Zahlarten ohne bestätigten Zahlungseingang, kommagetrennt (z. B. cod für Nachnahme). Sie werden für Bootsschul-Codes an der Kasse ausgeblendet; Bestellungen damit werden erst nach Zahlungseingang zugestellt.', 'bs-connector')],
            ['type' => 'sectionend', 'id' => 'bs_connector_section'],
        ];
    }

    /**
     * Geheimnis-Feld (nie mit Wert). Ist die Konstante in wp-config.php
     * gesetzt, blendet editable_fields() es aus.
     */
    private static function secret_field(string $key, string $title): array
    {
        return ['title' => $title, 'id' => self::OPTION.'['.$key.']', 'type' => 'password', 'value' => '', 'placeholder' => self::secret_placeholder($key), 'desc' => sprintf( /* translators: %s: Name der Konstante */ __('Empfohlen: stattdessen %s in wp-config.php setzen.', 'bs-connector'), self::SECRETS[$key])];
    }

    /**
     * Felder ohne die per Konstante festgelegten: diese stehen nur als Hinweis
     * im Formular (ohne Eingabefeld, Geheimnisse ohne Wert) und werden nie
     * gespeichert. Geheimnis-Felder gibt es nur, wenn Geheimnisse aus der
     * Datenbank erlaubt sind ($allow_db).
     */
    private static function editable_fields(bool $allow_db): array
    {
        $fields = [];
        foreach (self::fields() as $field) {
            $key = preg_match('/\[(\w+)\]$/', (string) ($field['id'] ?? ''), $m) ? $m[1] : null;
            if ($key !== null && (self::is_locked($key) || (! $allow_db && isset(self::SECRETS[$key])))) {
                continue;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Anleitung für die Geheimnisse ohne Konstante, solange die Datenbank
     * dafür nicht erlaubt ist (das Formular hat dann keine Geheimnis-Felder).
     */
    private static function secrets_constants_notice(bool $allow_db): array
    {
        $missing = array_values(array_filter(self::SECRETS, static fn (string $constant): bool => ! defined($constant)));
        if ($allow_db || $missing === []) {
            return [];
        }
        // In <code>: WooCommerce gibt die Beschreibung durch wptexturize aus, das
        // sonst typografische Anführungszeichen einsetzt -- kopiert wäre das kein PHP.
        $lines = implode(' ', array_map(static fn (string $constant): string => "<code>define( '".$constant."', '…' );</code>", $missing));

        return [[
            'title' => __('Geheimnisse in wp-config.php setzen', 'bs-connector'),
            'type' => 'title',
            'id' => 'bs_connector_secrets_constants',
            'desc' => sprintf(
                /* translators: %s: define()-Zeilen für wp-config.php */
                __('Die Geheimnisse werden nur aus wp-config.php gelesen. Tragen Sie dort vor der Zeile „That’s all, stop editing!“ ein: %s – die Werte entsprechen COMMERCE_SHOP_SECRET bzw. COMMERCE_OUTBOUND_SECRET der Bootsschul-Plattform. Ohne diese Konstanten ist die Anbindung aus.', 'bs-connector'),
                $lines
            ),
        ], ['type' => 'sectionend', 'id' => 'bs_connector_secrets_constants']];
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
        $allow_db = self::allow_db_secrets();
        woocommerce_admin_fields(array_merge(self::locked_notice(), self::secrets_constants_notice($allow_db), self::editable_fields($allow_db)));
    }

    /** Knopf „Verbindung testen“ unter dem Formular (nur mit CAPABILITY). */
    public static function render_connection_test(): void
    {
        if (! self::can_manage()) {
            return;
        }
        // Prüft Adresse, Schlüssel und Geheimnis mit GET /catalog (gespeicherte Werte).
        echo '<p><a class="button" href="'.esc_url(BS_Admin::connection_test_url()).'">'.esc_html__('Verbindung testen', 'bs-connector').'</a> ';
        echo '<span class="description">'.esc_html__('Verwendet die gespeicherten Einstellungen – vorher speichern.', 'bs-connector').'</span></p>';
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
        // Per Konstante festgelegt: nie aus dem Formular übernehmen.
        foreach (array_keys(self::CONSTANTS) as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
            if (self::is_locked($key) && isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION])) {
                unset($_POST[self::OPTION][$key]);
            }
        }
        // Geheimnisse aus der Datenbank nach dem Speichern erlaubt? Maßgeblich
        // ist das abgeschickte Kästchen (WooCommerce sendet „1“) samt Filter.
        // Wenn nein: keine Geheimnisse aus dem Formular übernehmen (auch nicht
        // aus einer selbst gebauten Anfrage) und den Update-Hinweis beenden.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
        $posted = isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION]) ? $_POST[self::OPTION] : [];
        $allow_db = (bool) apply_filters('bs_connector_allow_db_secrets', in_array($posted['allow_db_secrets'] ?? '', ['1', 'yes'], true));
        if (! $allow_db) {
            foreach (array_keys(self::SECRETS) as $key) {
                unset($_POST[self::OPTION][$key]);
            }
            delete_option(self::KEPT_DB_SECRETS_OPTION);
        }
        // Leeres Geheimnis-Feld = unverändert lassen.
        foreach (array_keys(self::SECRETS) as $key) {
            if (self::is_locked($key) || ! $allow_db) {
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce der Einstellungsseite.
            if (isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION]) && ($_POST[self::OPTION][$key] ?? '') === '' && ! empty($stored[$key])) {
                $_POST[self::OPTION][$key] = wp_slash($stored[$key]); // WooCommerce entfernt die Maskierung beim Speichern.
            }
        }
        woocommerce_update_options(self::editable_fields($allow_db));
        // Gesperrte Felder behalten ihren gespeicherten Wert -- außer
        // Geheimnissen: Steht das Geheimnis in wp-config.php, wird die Kopie in
        // der Datenbank nie verwendet, also beim Speichern entfernen.
        $saved = (array) get_option(self::OPTION, []);
        $changed = false;
        foreach (array_keys(self::SECRETS) as $key) {
            if (self::is_locked($key) && isset($saved[$key])) {
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
     * liegen (nicht ausblendbar, nur mit CAPABILITY).
     */
    /**
     * Firmenfeld an der Kasse ausgeblendet (Standard neuer WooCommerce-Installationen):
     * Jede Bestellung speichert dann eine leere Firma im Kundenkonto, die Plattform
     * erhält beim Abgleich keine Firma mehr und Rechnungen zeigen keine.
     */
    public static function company_field_notice(): void
    {
        if (! self::can_manage() || get_option('woocommerce_checkout_company_field', 'optional') !== 'hidden') {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Bootsschul-Plattform Connector:', 'bs-connector').'</strong> ';
        echo esc_html__('An der Kasse ist das Feld „Firmenname“ ausgeblendet. Jede Bestellung leert dadurch die Firma im Kundenkonto; die Bootsschul-Plattform erhält sie nicht mehr. Bitte das Feld auf „Optional“ oder „Erforderlich“ stellen (Kasse-Block im Website-Editor bzw. Design → Customizer → WooCommerce → Kasse).', 'bs-connector').'</p></div>';
    }

    public static function secrets_notice(): void
    {
        if (! self::can_manage()) {
            return;
        }
        $stored = self::stored_secrets();
        if ($stored === []) {
            return;
        }
        $constants = implode(', ', array_map(static fn ($key) => self::SECRETS[$key], $stored));
        $all_defined = array_filter($stored, static fn ($key) => ! self::is_locked($key)) === [];
        if ($all_defined) {
            $state = __('Sie werden nicht verwendet, weil die Konstanten in wp-config.php gesetzt sind. Speichern Sie die Einstellungen unter WooCommerce → Einstellungen → Bootsschule einmal, um sie aus der Datenbank zu entfernen.', 'bs-connector');
        } elseif (! self::allow_db_secrets()) {
            $state = __('Sie werden ignoriert, weil Geheimnisse aus der Datenbank nicht erlaubt sind – die Anbindung funktioniert erst mit den Konstanten in wp-config.php.', 'bs-connector');
        } else {
            $state = __('Empfohlen: in wp-config.php verschieben und danach „Geheimnisse aus der Datenbank erlauben“ ausschalten.', 'bs-connector');
            if (get_option(self::KEPT_DB_SECRETS_OPTION) === 'yes') {
                $state = __('Seit Version 1.4.0 sind Geheimnisse aus der Datenbank standardmäßig aus; beim Update wurde „Geheimnisse aus der Datenbank erlauben“ für diese Installation eingeschaltet, damit die Anbindung weiterläuft.', 'bs-connector').' '.$state;
            }
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Bootsschul-Plattform Connector:', 'bs-connector').'</strong> ';
        echo esc_html(sprintf( /* translators: %s: Namen der Konstanten */ __('Geheimnisse der Anbindung liegen in der Datenbank (wp_options). Sicherer ist wp-config.php: %s.', 'bs-connector'), $constants)).' ';
        echo esc_html($state).'</p></div>';
    }
}
