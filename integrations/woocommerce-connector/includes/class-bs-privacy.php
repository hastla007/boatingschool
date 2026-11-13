<?php

/**
 * Datenschutz-Werkzeuge von WordPress (Werkzeuge → Personenbezogene Daten
 * exportieren/löschen) für die Nutzer-Metadaten des Plugins:
 * bs_app_user_id, bs_app_tenant_id, die _bs_*-Merker und das Ergebnis der
 * USt-IdNr.-Prüfung (_bs_vat_*).
 *
 * Bestellpositionen (Zustellstatus) bleiben: sie gehören zur Buchhaltung.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Privacy
{
    /** Exportierte und gelöschte Nutzer-Metadaten (Schlüssel => Bezeichnung im Export). */
    const META_KEYS = [
        'bs_app_user_id' => 'App-Konto (Plattform)',
        'bs_app_tenant_id' => 'Bootsschule (Plattform)',
        '_bs_registered' => 'Bei der Plattform angemeldet',
        '_bs_provisioned' => 'Von der Plattform angelegt',
        '_bs_email_confirmed' => 'Bestätigte E-Mail-Adresse',
        '_bs_email_verify_sent' => 'Bestätigungsmail gesendet am',
        '_bs_email_verify_key' => 'Offener Bestätigungslink',
        '_bs_vat_number' => 'Geprüfte USt-IdNr.',
        '_bs_vat_status' => 'Ergebnis der USt-IdNr.-Prüfung (VIES)',
        '_bs_vat_checked_at' => 'USt-IdNr. geprüft am',
        '_bs_vat_request_id' => 'Abfragenummer der USt-IdNr.-Prüfung (VIES)',
        '_bs_vat_error' => 'Fehler der USt-IdNr.-Prüfung',
        '_bs_vat_attempts' => 'Versuche der USt-IdNr.-Prüfung',
    ];

    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_eraser']);
    }

    /**
     * Exportzeilen aus den Metadaten eines Nutzers (ohne WordPress testbar).
     * Der Einmalschlüssel eines offenen Links wird nicht ausgegeben, nur dass es ihn gibt.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array{name: string, value: string}>
     */
    public static function rows(array $meta): array
    {
        $rows = [];
        foreach (self::META_KEYS as $key => $label) {
            $value = $meta[$key] ?? '';
            if ($value === '' || $value === null || $value === false) {
                continue;
            }
            if ($key === '_bs_email_verify_key') {
                $value = 'ja';
            } elseif ($key === '_bs_email_verify_sent' || $key === '_bs_vat_checked_at') {
                $value = gmdate('Y-m-d H:i', (int) $value).' UTC';
            } elseif (in_array($key, ['_bs_registered', '_bs_provisioned'], true)) {
                $value = 'ja';
            }
            $rows[] = ['name' => $label, 'value' => (string) $value];
        }

        return $rows;
    }

    public static function register_exporter(array $exporters): array
    {
        $exporters['bs-connector'] = [
            'exporter_friendly_name' => __('Bootsschul-Plattform Connector', 'bs-connector'),
            'callback' => [__CLASS__, 'export'],
        ];

        return $exporters;
    }

    public static function register_eraser(array $erasers): array
    {
        $erasers['bs-connector'] = [
            'eraser_friendly_name' => __('Bootsschul-Plattform Connector', 'bs-connector'),
            'callback' => [__CLASS__, 'erase'],
        ];

        return $erasers;
    }

    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['data' => [], 'done' => true];
        }
        $meta = [];
        foreach (array_keys(self::META_KEYS) as $key) {
            $meta[$key] = get_user_meta($user->ID, $key, true);
        }
        $rows = self::rows($meta);
        $labels = self::translated_labels();
        foreach ($rows as &$row) {
            $row['name'] = $labels[$row['name']] ?? $row['name'];
        }
        unset($row);

        return [
            'data' => $rows === [] ? [] : [[
                'group_id' => 'bs-connector',
                'group_label' => __('Bootsschul-Plattform', 'bs-connector'),
                'item_id' => 'bs-connector-'.$user->ID,
                'data' => $rows,
            ]],
            'done' => true,
        ];
    }

    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $removed = false;
        if ($user) {
            foreach (array_keys(self::META_KEYS) as $key) {
                $removed = delete_user_meta($user->ID, $key) || $removed;
            }
            delete_transient(BS_Accounts::STATUS_CACHE.$user->ID);
            BS_Log::info('Datenschutz: Plugin-Metadaten eines Kunden gelöscht', ['customer_id' => $user->ID]);
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => $removed ? [__('Die Verknüpfung des Kundenkontos mit der Bootsschul-Plattform wurde im Shop entfernt. Bestellungen und ihr Zustellstatus bleiben für die Buchhaltung erhalten.', 'bs-connector')] : [],
            'done' => true,
        ];
    }

    /** Übersetzte Bezeichnungen (die Schlüssel von META_KEYS stehen so in der .pot). */
    private static function translated_labels(): array
    {
        return [
            'App-Konto (Plattform)' => __('App-Konto (Plattform)', 'bs-connector'),
            'Bootsschule (Plattform)' => __('Bootsschule (Plattform)', 'bs-connector'),
            'Bei der Plattform angemeldet' => __('Bei der Plattform angemeldet', 'bs-connector'),
            'Von der Plattform angelegt' => __('Von der Plattform angelegt', 'bs-connector'),
            'Bestätigte E-Mail-Adresse' => __('Bestätigte E-Mail-Adresse', 'bs-connector'),
            'Bestätigungsmail gesendet am' => __('Bestätigungsmail gesendet am', 'bs-connector'),
            'Offener Bestätigungslink' => __('Offener Bestätigungslink', 'bs-connector'),
            'Geprüfte USt-IdNr.' => __('Geprüfte USt-IdNr.', 'bs-connector'),
            'Ergebnis der USt-IdNr.-Prüfung (VIES)' => __('Ergebnis der USt-IdNr.-Prüfung (VIES)', 'bs-connector'),
            'USt-IdNr. geprüft am' => __('USt-IdNr. geprüft am', 'bs-connector'),
            'Abfragenummer der USt-IdNr.-Prüfung (VIES)' => __('Abfragenummer der USt-IdNr.-Prüfung (VIES)', 'bs-connector'),
            'Fehler der USt-IdNr.-Prüfung' => __('Fehler der USt-IdNr.-Prüfung', 'bs-connector'),
            'Versuche der USt-IdNr.-Prüfung' => __('Versuche der USt-IdNr.-Prüfung', 'bs-connector'),
        ];
    }
}
