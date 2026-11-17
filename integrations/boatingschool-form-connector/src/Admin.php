<?php

/**
 * Einstellungsseite unter „Form Captain“: Adresse der Plattform, Schlüssel-ID, Geheimnis (write-only, maskiert),
 * erlaubte Hosts der Quell-URLs, Löschung in der Plattform, „Verbindung testen“ (Ping, legt keinen Lead an).
 * Werte aus wp-config.php werden angezeigt, aber nicht überschrieben. Zustellstatus, letzter Fehler und
 * „Wiederholen“ stehen in Form Captain unter Integrationen → Integrations-Jobs (Outbox des Kerns).
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Admin
{
    public const SLUG = 'boatingschool-form-connector';

    public const CAPABILITY = 'manage_options';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_boatingschool_form_save', [self::class, 'save']);
        add_action('admin_post_boatingschool_form_ping', [self::class, 'ping']);
    }

    public static function menu(): void
    {
        add_submenu_page('form-captain', __('BoatingSchool-Leads', 'boatingschool-form-connector'), __('BoatingSchool-Leads', 'boatingschool-form-connector'), self::CAPABILITY, self::SLUG, [self::class, 'render']);
    }

    public static function save(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'boatingschool-form-connector'), '', ['response' => 403]);
        }
        check_admin_referer('boatingschool_form_save');
        Settings::save(wp_unslash($_POST['boatingschool_form'] ?? []));
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function ping(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'boatingschool-form-connector'), '', ['response' => 403]);
        }
        check_admin_referer('boatingschool_form_ping');
        $response = Client::ping();
        $result = $response['status'] === 200 && ! empty($response['body']['ok'])
            ? 'ok'
            : ($response['error'] !== '' ? $response['error'] : ($response['code'] !== '' ? $response['code'] : 'http_'.$response['status']));
        set_transient('boatingschool_form_ping_'.get_current_user_id(), $result, 300);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'pinged' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        $ping = isset($_GET['pinged']) ? (string) get_transient('boatingschool_form_ping_'.get_current_user_id()) : '';
        $readonly = static fn (string $key): string => Settings::from_constant($key) ? ' readonly' : '';
        echo '<div class="wrap"><h1>'.esc_html__('BoatingSchool-Leads', 'boatingschool-form-connector').'</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>'.esc_html__('Gespeichert.', 'boatingschool-form-connector').'</p></div>';
        }
        if ($ping !== '') {
            $class = $ping === 'ok' ? 'notice-success' : 'notice-error';
            $text = $ping === 'ok' ? __('Verbindung und Schlüssel in Ordnung (kein Lead angelegt).', 'boatingschool-form-connector') : sprintf(__('Verbindungstest fehlgeschlagen: %s', 'boatingschool-form-connector'), $ping);
            echo '<div class="notice '.esc_attr($class).'"><p>'.esc_html($text).'</p></div>';
        }
        echo '<p>'.esc_html__('Sendet Einsendungen der Formulare mit der Aktion „Als Lead an BoatingSchool senden“ an die Lead-Inbox der Plattform. Übertragen werden nur die im Formular zugeordneten Felder (E-Mail, Name, Telefon, Organisation, Rolle, Schulgröße, Nachricht), Formular- und Eintrags-ID, die Seite des Formulars und ein Verweis auf die Einwilligung (Zeitpunkt, Revision, optional Hash) – nie IP-Adresse, Browser, versteckte oder sensible Felder. Die Plattform legt daraus kein Konto an.', 'boatingschool-form-connector').'</p>';
        echo '<p>'.esc_html__('Zustellstatus, letzter Fehler und „Wiederholen“: Form Captain → Integrationen → Integrations-Jobs.', 'boatingschool-form-connector').'</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('boatingschool_form_save');
        echo '<input type="hidden" name="action" value="boatingschool_form_save"><table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bsf-endpoint">'.esc_html__('Adresse der Plattform (HTTPS)', 'boatingschool-form-connector').'</label></th><td><input id="bsf-endpoint" name="boatingschool_form[endpoint]" type="url" class="regular-text" value="'.esc_attr(Settings::endpoint()).'"'.$readonly('endpoint').'></td></tr>';
        echo '<tr><th scope="row"><label for="bsf-key">'.esc_html__('Schlüssel-ID', 'boatingschool-form-connector').'</label></th><td><input id="bsf-key" name="boatingschool_form[key_id]" class="regular-text" value="'.esc_attr(Settings::key_id()).'"'.$readonly('key_id').'></td></tr>';
        echo '<tr><th scope="row"><label for="bsf-secret">'.esc_html__('Geheimnis', 'boatingschool-form-connector').'</label></th><td><input id="bsf-secret" name="boatingschool_form[secret]" type="password" autocomplete="new-password" class="regular-text" value="" placeholder="'.esc_attr(Settings::masked_secret()).'"'.$readonly('secret').'>'
            .'<p class="description">'.esc_html__('Wird nie angezeigt. Leer lassen = unverändert. Besser: BOATINGSCHOOL_FORM_SECRET in wp-config.php.', 'boatingschool-form-connector').'</p></td></tr>';
        echo '<tr><th scope="row"><label for="bsf-hosts">'.esc_html__('Erlaubte Hosts der Quell-URLs', 'boatingschool-form-connector').'</label></th><td><input id="bsf-hosts" name="boatingschool_form[allowed_hosts]" class="regular-text" value="'.esc_attr(implode(', ', Settings::allowed_hosts())).'"'.$readonly('allowed_hosts').'></td></tr>';
        echo '<tr><th scope="row">'.esc_html__('Löschung', 'boatingschool-form-connector').'</th><td><label><input type="checkbox" name="boatingschool_form[remote_delete]" value="1"'.(Settings::remote_delete() ? ' checked' : '').'> '.esc_html__('Wird ein Eintrag in Form Captain gelöscht oder die Einwilligung widerrufen, den Lead in der Plattform löschen.', 'boatingschool-form-connector').'</label></td></tr>';
        echo '</table>';
        submit_button(__('Speichern', 'boatingschool-form-connector'));
        echo '</form>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('boatingschool_form_ping');
        echo '<input type="hidden" name="action" value="boatingschool_form_ping">';
        submit_button(__('Verbindung testen', 'boatingschool-form-connector'), 'secondary', 'submit', false);
        echo '</form>';
        printf(
            '<p class="description">'.esc_html__('Zugestellte Leads (Merkbuch für Löschungen): %1$d · offene Löschungen: %2$d', 'boatingschool-form-connector').'</p></div>',
            count(Ledger::all()),
            count(Ledger::pending())
        );
    }
}
