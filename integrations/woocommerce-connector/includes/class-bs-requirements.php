<?php

/**
 * Mindestanforderungen (Entscheidung MINVER-1 vom 17.11.2026, gilt für
 * Plattform, Plugins und Connectoren): PHP 8.5, WordPress 7.0,
 * WooCommerce 11.2.2 (11.2.0/11.2.1 übernehmen Bestell- und Zahlungsdaten
 * eines früheren Store-API-Checkouts im selben PHP-Prozess, woocommerce#70637); MySQL 9.7 bzw. MariaDB 12.3 nur als Empfehlung.
 *
 * - Aktivieren: Erfüllt die Umgebung PHP/WordPress/WooCommerce nicht, bricht
 *   die Aktivierung mit Hinweis ab (das Plugin läuft gar nicht erst an).
 * - Laufender Betrieb (z. B. nach einem Downgrade von PHP oder WooCommerce):
 *   nur ein Hinweis für Administratoren, das Plugin läuft weiter. Würde es
 *   nicht mehr starten, fänden bereits eingeplante Zustellungen in Action
 *   Scheduler keinen Rückruf mehr (Action Scheduler markiert sie dann als
 *   fehlgeschlagen, sie wären verloren), und Bootsschul-Produkte blieben ohne
 *   Einkaufsprüfung und ohne Zustellung kaufbar.
 * - Nur unterhalb von RUNTIME_PHP (dort kann der Code des Plugins nicht
 *   laufen) startet es nicht. Eingeplante Aufgaben des Plugins werden dann
 *   unverändert um eine Stunde verschoben statt verworfen.
 * - Datenbank älter als empfohlen: nur ein Hinweis.
 *
 * Bewusst in einfacher Syntax, damit diese Datei auch unter alten
 * PHP-Versionen geladen werden kann und den Hinweis zeigt.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

if (! function_exists('bs_connector_php_version')) {
    /** Laufende PHP-Version; die Plugin-Tests ersetzen die Funktion, um andere Versionen zu prüfen. */
    function bs_connector_php_version()
    {
        return PHP_VERSION;
    }
}

final class BS_Requirements
{
    const PHP = '8.5';

    const WORDPRESS = '7.0';

    const WOOCOMMERCE = '11.2.2';

    const MYSQL = '9.7';

    const MARIADB = '12.3';

    /** Darunter läuft der Code des Plugins nicht (Syntax ab PHP 8.1). */
    const RUNTIME_PHP = '8.1';

    /** Aufgaben des Plugins in Action Scheduler (wie BS_Orders, BS_Vat_Check, BS_Accounts). */
    const SCHEDULED_HOOKS = ['bs_connector_deliver_item', 'bs_connector_void_item', 'bs_connector_vat_recheck', 'bs_connector_sync_account'];

    /** Verschiebung eingeplanter Aufgaben, solange das Plugin nicht startet. */
    const DEFER_SECONDS = 3600;

    /**
     * Fehlende Mindestanforderungen als Texte (leer = alles erfüllt).
     * Versionen als Parameter, damit sich die Prüfung ohne echte Umgebung
     * testen lässt; null bei WooCommerce = nicht aktiv.
     *
     * @return list<string>
     */
    public static function problems($php, $wordpress, $woocommerce)
    {
        $problems = [];
        if (! self::at_least($php, self::PHP)) {
            /* translators: 1: Mindestversion, 2: aktuelle Version */
            $problems[] = sprintf(__('PHP %1$s oder neuer (aktuell: %2$s)', 'bs-connector'), self::PHP, $php);
        }
        if (! self::at_least($wordpress, self::WORDPRESS)) {
            /* translators: 1: Mindestversion, 2: aktuelle Version */
            $problems[] = sprintf(__('WordPress %1$s oder neuer (aktuell: %2$s)', 'bs-connector'), self::WORDPRESS, $wordpress);
        }
        if ($woocommerce === null || $woocommerce === '') {
            /* translators: %s: Mindestversion */
            $problems[] = sprintf(__('WooCommerce %s oder neuer (nicht aktiv)', 'bs-connector'), self::WOOCOMMERCE);
        } elseif (! self::at_least($woocommerce, self::WOOCOMMERCE)) {
            /* translators: 1: Mindestversion, 2: aktuelle Version */
            $problems[] = sprintf(__('WooCommerce %1$s oder neuer (aktuell: %2$s)', 'bs-connector'), self::WOOCOMMERCE, $woocommerce);
        }

        return $problems;
    }

    /**
     * Hinweis zur Datenbank (null = Version erfüllt die Empfehlung oder ist
     * unbekannt). $server_info wie $wpdb->db_server_info(), z. B. „9.7.0“,
     * „8.4.13“ oder „5.5.5-11.4.2-MariaDB-ubu2404“.
     *
     * @return string|null
     */
    public static function database_problem($server_info)
    {
        $server_info = (string) $server_info;
        $mariadb = stripos($server_info, 'mariadb') !== false;
        if ($mariadb && strpos($server_info, '5.5.5-') === 0) {
            $server_info = substr($server_info, 6);
        }
        if (! preg_match('/^\d+\.\d+(\.\d+)?/', $server_info, $match)) {
            return null;
        }
        $minimum = $mariadb ? self::MARIADB : self::MYSQL;
        if (self::at_least($match[0], $minimum)) {
            return null;
        }

        /* translators: 1: MySQL oder MariaDB, 2: aktuelle Version, 3: empfohlene Mindestversion */
        return sprintf(__('Die Datenbank (%1$s %2$s) ist älter als empfohlen (%1$s %3$s oder neuer). Der Connector läuft weiter; bitte die Datenbank aktualisieren.', 'bs-connector'), $mariadb ? 'MariaDB' : 'MySQL', $match[0], $minimum);
    }

    /** Version erfüllt die Mindestversion (8.5.0-dev zählt als 8.5). */
    public static function at_least($version, $minimum)
    {
        if (! preg_match('/^\d+(\.\d+){0,2}/', (string) $version, $match)) {
            return false;
        }

        return version_compare($match[0], $minimum, '>=');
    }

    /** Läuft der Code des Plugins unter dieser PHP-Version überhaupt? */
    public static function can_run($php)
    {
        return self::at_least($php, self::RUNTIME_PHP);
    }

    /** @return list<string> Probleme der laufenden Umgebung. */
    public static function current_problems()
    {
        return self::problems(bs_connector_php_version(), (string) get_bloginfo('version'), defined('WC_VERSION') ? (string) WC_VERSION : null);
    }

    /**
     * Aktivierung: Abbruch mit Hinweis, wenn Mindestanforderungen fehlen.
     *
     * @param  list<string>  $problems
     */
    public static function abort_activation(array $problems, $plugin_file)
    {
        if ($problems === []) {
            return;
        }
        deactivate_plugins(plugin_basename($plugin_file));
        wp_die(
            '<p>'.esc_html__('Der Bootsschul-Plattform Connector wurde nicht aktiviert. Es fehlen:', 'bs-connector').'</p><ul><li>'
            .implode('</li><li>', array_map('esc_html', $problems)).'</li></ul>',
            esc_html__('Mindestanforderungen nicht erfüllt', 'bs-connector'),
            ['back_link' => true]
        );
    }

    /**
     * Hinweise für Administratoren im Backend: fehlende Mindestanforderungen
     * und eine zu alte Datenbank. Geprüft erst bei der Ausgabe, weil
     * WooCommerce beim Laden dieser Datei noch nicht geladen sein muss.
     */
    public static function register_notices()
    {
        add_action(
            'admin_notices',
            static function () {
                if (! current_user_can('manage_options')) {
                    return;
                }
                global $wpdb;
                BS_Requirements::render_notices(
                    BS_Requirements::current_problems(),
                    BS_Requirements::can_run(bs_connector_php_version()) && class_exists('WooCommerce'),
                    isset($wpdb) && method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : ''
                );
            }
        );
    }

    /**
     * @param  list<string>  $problems
     * @param  bool  $running  Läuft das Plugin trotzdem? Nicht ohne WooCommerce und nicht unter RUNTIME_PHP.
     */
    public static function render_notices(array $problems, $running, $db_server_info)
    {
        if ($problems !== []) {
            $intro = $running
                ? __('Der Bootsschul-Plattform Connector läuft in einer nicht unterstützten Umgebung weiter. Bitte aktualisieren:', 'bs-connector')
                : __('Der Bootsschul-Plattform Connector ist angehalten. Eingeplante Zustellungen bleiben erhalten und laufen weiter, sobald die Anforderungen erfüllt sind. Es fehlen:', 'bs-connector');
            echo '<div class="notice notice-error"><p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $problems)).'</li></ul></div>';
        }
        $database = self::database_problem($db_server_info);
        if ($database !== null) {
            echo '<div class="notice notice-warning"><p>'.esc_html($database).'</p></div>';
        }
    }

    /**
     * Plugin startet nicht (PHP unter RUNTIME_PHP): eingeplante Aufgaben des
     * Plugins nicht verwerfen, sondern mit denselben Argumenten verschieben.
     * Ohne Rückruf markierte Action Scheduler sie als fehlgeschlagen.
     */
    public static function defer_scheduled_actions()
    {
        foreach (self::SCHEDULED_HOOKS as $hook) {
            add_action(
                $hook,
                static function () use ($hook) {
                    BS_Requirements::defer($hook, func_get_args());
                },
                10,
                5
            );
        }
    }

    /** @param list<mixed> $args */
    public static function defer($hook, array $args)
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + self::DEFER_SECONDS, $hook, $args, 'bs-connector');
        }
    }
}
