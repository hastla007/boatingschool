<?php

/**
 * Statusseite (Werkzeuge → BoatingSchool Workflow, WF-CUST1-08): Version,
 * Lizenz, Workflow Captain (vorhanden, angemeldet, freigegeben), Einrichtung
 * (nur ob, nie Werte), Contract-Handshake mit der App, Testereignis,
 * Payload-Mapping und Fehlerdiagnose (Zähler und Codes).
 *
 * Nur für Administratoren (manage_options).
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Admin
{
    const CAPABILITY = 'manage_options';

    const SLUG = 'boatingschool-workflow';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('admin_post_bsw_handshake', [__CLASS__, 'handle_handshake']);
        add_action('admin_post_bsw_test_event', [__CLASS__, 'handle_test_event']);
        add_action('admin_post_bsw_reset_diagnostics', [__CLASS__, 'handle_reset']);
    }

    public static function menu(): void
    {
        add_management_page(__('BoatingSchool Workflow', 'boatingschool-workflow-connector'), __('BoatingSchool Workflow', 'boatingschool-workflow-connector'), self::CAPABILITY, self::SLUG, [__CLASS__, 'render']);
    }

    /** Text zur Laufzeit nach F-18a, oder null, wenn alles da ist. */
    public static function runtime_message(?string $runtime): ?string
    {
        if ($runtime === null || $runtime === 'ok') {
            return null;
        }

        return $runtime === BSW_Workflow::RUNTIME_NOT_ALLOWED
            ? __('Workflow Captain Pro fehlt oder das Recht fehlt: Workflow Captain Pro ist aktiv, seine Lizenz gewährt das Recht „workflow.custom_connectors“ aber nicht. Die eigene Lizenz dieses Connectors ersetzt das nicht.', 'boatingschool-workflow-connector')
            : __('Workflow Captain Pro fehlt oder das Recht fehlt: Kundenconnectoren brauchen Workflow Captain Pro mit dem Recht „workflow.custom_connectors“. Die eigene Lizenz dieses Connectors ersetzt das nicht.', 'boatingschool-workflow-connector');
    }

    /** Admin-Hinweis, solange Workflow Captain Pro oder dessen Recht fehlt. */
    public static function notice(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! BSW_Workflow::available()) {
            return;
        }
        $message = self::runtime_message(BSW_Workflow::runtime());
        if ($message !== null) {
            echo '<div class="notice notice-error"><p>'.esc_html__('BoatingSchool Workflow Connector:', 'boatingschool-workflow-connector').' '.esc_html($message).'</p></div>';
        }
    }

    /** Zustand für die Seite (auch für Tests), ohne Geheimnisse. */
    public static function status(): array
    {
        return [
            'version' => BSW_CONNECTOR_VERSION,
            'contract_version' => BSW_Manifest::CONTRACT_VERSION,
            'license' => BSW_License::status(),
            'workflow_captain' => BSW_Workflow::available(),
            'workflow_captain_contract' => defined('WORKFLOW_CAPTAIN_CONTRACT_VERSION') ? (string) WORKFLOW_CAPTAIN_CONTRACT_VERSION : null,
            'registration' => BSW_Workflow::registration(),
            'runtime' => BSW_Workflow::available() ? (BSW_Workflow::runtime() ?? 'ok') : null,
            'enabled' => BSW_Workflow::enabled(),
            'settings' => BSW_Settings::overview(),
            'diagnostics' => BSW_Diagnostics::get(),
        ];
    }

    /** Testereignis: derselbe Weg in Workflow Captain wie ein echtes Ereignis (Lizenz, Anmeldung, Freigabe, Mapping). */
    public static function send_test_event(int $now): string
    {
        if (! BSW_License::valid()) {
            return 'license_'.BSW_License::status();
        }
        if (! BSW_Workflow::available()) {
            return 'workflow_captain_missing';
        }
        if (BSW_Workflow::runtime() !== null) {
            return (string) BSW_Workflow::runtime();
        }
        if (! BSW_Workflow::registered()) {
            return 'connector_not_registered';
        }
        if (! BSW_Workflow::enabled()) {
            return 'connector_not_enabled';
        }

        return BSW_Workflow::capture(BSW_Payload_Mapper::test_event($now));
    }

    public static function handle_handshake(): void
    {
        self::guard('bsw_handshake');
        $r = BSW_App_Client::handshake(time(), BSW_CONNECTOR_VERSION);
        self::back($r['ok'] ? 'handshake_ok' : 'handshake_'.$r['code']);
    }

    public static function handle_test_event(): void
    {
        self::guard('bsw_test_event');
        self::back('test_'.self::send_test_event(time()));
    }

    public static function handle_reset(): void
    {
        self::guard('bsw_reset_diagnostics');
        BSW_Diagnostics::reset();
        self::back('reset');
    }

    private static function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'boatingschool-workflow-connector'), 403);
        }
        check_admin_referer($action);
    }

    private static function back(string $result): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'bsw_result' => preg_replace('/[^a-z0-9_:.-]/', '', strtolower($result))], admin_url('tools.php')));
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        $s = self::status();
        $d = $s['diagnostics'];
        $yes = esc_html__('ja', 'boatingschool-workflow-connector');
        $no = esc_html__('nein', 'boatingschool-workflow-connector');
        $time = static fn ($ts) => $ts ? esc_html(gmdate('Y-m-d H:i:s', (int) $ts).' UTC') : '–';
        $result = isset($_GET['bsw_result']) ? preg_replace('/[^a-z0-9_:.-]/', '', strtolower((string) wp_unslash($_GET['bsw_result']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        echo '<div class="wrap"><h1>'.esc_html__('BoatingSchool Workflow Connector', 'boatingschool-workflow-connector').'</h1>';
        if ($result !== '') {
            echo '<div class="notice notice-info"><p>'.esc_html__('Ergebnis:', 'boatingschool-workflow-connector').' <code>'.esc_html($result).'</code></p></div>';
        }

        echo '<h2>'.esc_html__('Status', 'boatingschool-workflow-connector').'</h2><table class="widefat striped"><tbody>';
        $rows = [
            __('Plugin-Version', 'boatingschool-workflow-connector') => esc_html($s['version']),
            __('Vertragsversion (App)', 'boatingschool-workflow-connector') => esc_html($s['contract_version']),
            __('Lizenz', 'boatingschool-workflow-connector') => '<code>'.esc_html($s['license']).'</code>',
            __('Workflow Captain aktiv', 'boatingschool-workflow-connector') => ($s['workflow_captain'] ? $yes : $no).($s['workflow_captain_contract'] ? ' ('.esc_html($s['workflow_captain_contract']).')' : ''),
            __('Workflow Captain Pro (Laufzeit)', 'boatingschool-workflow-connector') => $s['runtime'] === null ? '–' : ($s['runtime'] === 'ok' ? $yes : '<code>'.esc_html($s['runtime']).'</code> '.esc_html((string) self::runtime_message($s['runtime']))),
            __('Anmeldung in Workflow Captain', 'boatingschool-workflow-connector') => '<code>'.esc_html((string) ($s['registration'] ?? $d['registration'] ?? '–')).'</code>',
            __('Freigegeben (Eigene Connectoren)', 'boatingschool-workflow-connector') => $s['enabled'] ? $yes : $no,
            __('App-Adresse eingerichtet', 'boatingschool-workflow-connector') => $s['settings']['app_url'] ? $yes : $no,
            __('Schlüssel Plugin → App', 'boatingschool-workflow-connector') => $s['settings']['outbound_key'] ? $yes : $no,
            __('Schlüssel App → Plugin', 'boatingschool-workflow-connector') => ($s['settings']['inbound_key'] ? $yes : $no).($s['settings']['inbound_rotation'] ? ' '.esc_html__('(Wechsel läuft: zwei Schlüssel)', 'boatingschool-workflow-connector') : ''),
            __('Ereignis-Endpunkt', 'boatingschool-workflow-connector') => '<code>'.esc_html(rest_url(BSW_Receiver::NAMESPACE.'/events')).'</code>',
        ];
        foreach ($rows as $label => $value) {
            echo '<tr><th>'.esc_html($label).'</th><td>'.$value.'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped
        }
        echo '</tbody></table>';

        echo '<h2>'.esc_html__('Prüfen', 'boatingschool-workflow-connector').'</h2><p>';
        foreach (['bsw_handshake' => __('Contract-Handshake mit der App', 'boatingschool-workflow-connector'), 'bsw_test_event' => __('Testereignis an Workflow Captain', 'boatingschool-workflow-connector'), 'bsw_reset_diagnostics' => __('Diagnose zurücksetzen', 'boatingschool-workflow-connector')] as $action => $label) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px">';
            wp_nonce_field($action);
            echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><button class="button">'.esc_html($label).'</button></form>';
        }
        echo '</p>';
        if (is_array($d['handshake'])) {
            echo '<p>'.esc_html__('Letzter Handshake:', 'boatingschool-workflow-connector').' <code>'.esc_html((string) $d['handshake']['code']).'</code>, '.esc_html__('App-Vertrag', 'boatingschool-workflow-connector').' <code>'.esc_html((string) ($d['handshake']['contract_version'] ?? '–')).'</code>, '.$time($d['handshake']['at']).'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '<h2>'.esc_html__('Zustellung und Fehler', 'boatingschool-workflow-connector').'</h2><table class="widefat striped"><tbody>';
        echo '<tr><th>'.esc_html__('Empfangen / erfasst / Doppel', 'boatingschool-workflow-connector').'</th><td>'.(int) $d['received'].' / '.(int) $d['captured'].' / '.(int) $d['duplicates'].'</td></tr>';
        echo '<tr><th>'.esc_html__('Letztes Ereignis', 'boatingschool-workflow-connector').'</th><td>'.$time($d['last_event_at']).'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<tr><th>'.esc_html__('Abgelehnt (Code: Anzahl)', 'boatingschool-workflow-connector').'</th><td>';
        foreach ((array) $d['refused'] as $code => $count) {
            echo '<code>'.esc_html((string) $code).'</code>: '.(int) $count.' ';
        }
        echo '</td></tr><tr><th>'.esc_html__('Befehle an die App / Fehler', 'boatingschool-workflow-connector').'</th><td>'.(int) $d['commands'].' / ';
        foreach ((array) $d['command_errors'] as $code => $count) {
            echo '<code>'.esc_html((string) $code).'</code>: '.(int) $count.' ';
        }
        echo '</td></tr></tbody></table>';
        echo '<p class="description">'.esc_html__('Wiederholung: Die App stellt jedes Ereignis bis zu 8-mal zu (1 Min. bis 24 Std.); ein Doppel gilt als zugestellt. Endgültig fehlgeschlagene Ereignisse reiht der Betreiber der App mit „php artisan app:workflow-outbox --requeue=<Event-ID>“ erneut ein.', 'boatingschool-workflow-connector').'</p>';

        echo '<h2>'.esc_html__('Payload-Mapping', 'boatingschool-workflow-connector').'</h2><table class="widefat striped"><thead><tr><th>'.esc_html__('Ereignis der App (Version)', 'boatingschool-workflow-connector').'</th><th>'.esc_html__('Typ in Workflow Captain', 'boatingschool-workflow-connector').'</th><th>'.esc_html__('Felder', 'boatingschool-workflow-connector').'</th></tr></thead><tbody>';
        foreach (BSW_Manifest::APP_EVENTS as $type => [$version, $fields]) {
            $list = [];
            foreach ($fields as $field => $kind) {
                $list[] = esc_html($field).' <small>('.esc_html(BSW_Manifest::field_type($kind)).')</small>';
            }
            echo '<tr><td><code>'.esc_html($type).'</code> v'.(int) $version.'</td><td><code>'.esc_html(BSW_Manifest::type($type)).'</code></td><td>'.implode(', ', $list).'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table><p class="description">'.esc_html__('Nur Referenzen, Status, Mengen und Kurs-/Produktschlüssel. Nie Codes, Verknüpfungscodes, Passwörter, E-Mail-Adressen, Namen oder USt-IDs.', 'boatingschool-workflow-connector').'</p></div>';
    }
}
