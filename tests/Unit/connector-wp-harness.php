<?php

/**
 * Minimaler WordPress-Ersatz für Plugin-Tests (ConnectorAccountsAndSettingsTest):
 * läuft in einem eigenen PHP-Prozess ohne Laravel (dessen __() u. a. kollidierten),
 * führt ein Szenario mit den echten Plugin-Klassen aus und gibt Beobachtungen
 * als JSON aus. Kein Test für echtes WordPress -- nur für die Plugin-Logik.
 *
 * Aufruf: php connector-wp-harness.php <szenario> '<json-optionen>'
 */
[$scenario, $options] = [$argv[1] ?? '', json_decode($argv[2] ?? '{}', true) ?: []];

define('ABSPATH', __DIR__.'/');
define('MINUTE_IN_SECONDS', 60);
foreach ($options['constants'] ?? [] as $name => $value) {
    define($name, $value);
}

$GLOBALS['bs_clock'] = 1_700_000_000;
$GLOBALS['bs_transients'] = [];
$GLOBALS['bs_api_calls'] = [];
$GLOBALS['bs_api_responses'] = $options['responses'] ?? [];
$GLOBALS['bs_caps'] = $options['caps'] ?? [];
$GLOBALS['bs_options'] = $options['stored'] ?? [];
$GLOBALS['bs_rendered'] = null;
$GLOBALS['bs_saved'] = null;

function get_transient($key)
{
    $t = $GLOBALS['bs_transients'][$key] ?? null;

    return $t !== null && ($t['expires'] === 0 || $t['expires'] > $GLOBALS['bs_clock']) ? $t['value'] : false;
}
function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['bs_transients'][$key] = ['value' => $value, 'expires' => $ttl ? $GLOBALS['bs_clock'] + $ttl : 0];

    return true;
}
function delete_transient($key)
{
    unset($GLOBALS['bs_transients'][$key]);

    return true;
}
function __($text, $domain = null)
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_html__($text, $domain = null)
{
    return esc_html($text);
}
function esc_html_e($text, $domain = null)
{
    echo esc_html($text);
}
function esc_attr($text)
{
    return esc_html($text);
}
function wp_nonce_field($action, $name)
{
    echo '<input type="hidden" name="'.$name.'">';
}
function get_current_user_id()
{
    return 7;
}
function current_user_can($cap)
{
    return in_array($cap, $GLOBALS['bs_caps'], true);
}
/** Kunde 7 mit bestätigter E-Mail-Adresse (E-Mail-Bestätigung vor der Verknüpfung). */
function get_userdata($id)
{
    return (object) ['ID' => $id, 'user_email' => 'kunde@example.test'];
}
function wp_get_current_user()
{
    return get_userdata(get_current_user_id());
}
function get_user_meta($id, $key, $single = false)
{
    return ['_bs_email_confirmed' => 'kunde@example.test'][$key] ?? '';
}
function get_option($name, $default = false)
{
    return $GLOBALS['bs_options'][$name] ?? $default;
}
function update_option($name, $value)
{
    $GLOBALS['bs_options'][$name] = $value;

    return true;
}
function apply_filters($hook, $value)
{
    return $value;
}
function esc_url($url)
{
    return esc_attr($url);
}
function wp_slash($value)
{
    return $value;
}
function woocommerce_admin_fields($fields)
{
    $GLOBALS['bs_rendered'] = $fields;
}
function woocommerce_update_options($fields)
{
    $GLOBALS['bs_saved'] = $fields;
}

/** Ersatz für die Admin-Seiten (nur die URL des Verbindungstests). */
final class BS_Admin
{
    public static function connection_test_url(): string
    {
        return 'admin-post.php?action=bs_connector_test';
    }
}

/** Ersatz für den HTTP-Client: zeichnet Aufrufe auf, Antworten aus den Optionen. */
final class BS_Api_Client
{
    public static function request(string $method, string $path, ?array $data = null, array $extra_headers = []): array
    {
        $GLOBALS['bs_api_calls'][] = $method.' '.$path;
        $status = array_shift($GLOBALS['bs_api_responses']);
        $body = $status === 200 ? ['data' => ['status' => 'linked', 'can_purchase' => true, 'tenant' => ['name' => 'Segelschule Möwe'], 'deliverable_course_codes' => ['SRC']]] : null;

        return ['status' => $status, 'body' => $body, 'error' => $status === 200 ? null : 'HTTP '.$status];
    }
}

$includes = dirname(__DIR__, 2).'/integrations/woocommerce-connector/includes/';
require $includes.'class-bs-settings.php';
require $includes.'class-bs-email-verification.php';
require $includes.'class-bs-accounts.php';

$out = [];
$calls = static fn (): int => count($GLOBALS['bs_api_calls']);
$advance = static function (int $seconds): void {
    $GLOBALS['bs_clock'] += $seconds;
};
$fieldIds = static fn (?array $fields): ?array => $fields === null ? null : array_values(array_map(static fn ($f) => $f['id'] ?? $f['type'], $fields));

switch ($scenario) {
    case 'status':
        // Fehlschlag (404) wird kurz gemerkt: Warenkorb-Aufrufe lösen keine Anfragen aus.
        $out['first'] = BS_Accounts::status(7);
        $out['again'] = BS_Accounts::status(7);
        $out['calls_after_negative'] = $calls();
        $advance(61);
        // Nach Ablauf frisch an der Kasse (200) ...
        $out['checkout'] = BS_Accounts::status(7, true)['can_purchase'] ?? null;
        $out['calls_after_checkout'] = $calls();
        // ... und weitere frische Abfragen innerhalb von 10 s aus dem Cache.
        $out['checkout_again'] = BS_Accounts::status(7, true)['can_purchase'] ?? null;
        $out['calls_after_checkout_again'] = $calls();
        $advance(11);
        BS_Accounts::status(7, true);
        $out['calls_after_interval'] = $calls();
        // Netzfehler (null) an der Kasse: ebenfalls gemerkt, auch für die nächste frische Abfrage im Intervall.
        $advance(301);
        $out['checkout_network_error'] = BS_Accounts::status(7, true);
        $out['checkout_network_error_again'] = BS_Accounts::status(7, true);
        $out['calls_end'] = $calls();
        break;

    case 'render_endpoint':
        // „Mein Konto“ neu laden: nur die erste Anzeige fragt die App.
        ob_start();
        BS_Accounts::render_endpoint();
        BS_Accounts::render_endpoint();
        BS_Accounts::render_endpoint();
        $html = ob_get_clean();
        $out['calls_after_reloads'] = $calls();
        $out['shows_school'] = str_contains($html, 'Segelschule Möwe');
        // Nach einem Verknüpfungsversuch (handle_link_form verwirft den Status): frisch.
        BS_Accounts::forget_status(7);
        ob_start();
        BS_Accounts::render_endpoint();
        ob_end_clean();
        $out['calls_after_link'] = $calls();
        break;

    case 'settings':
        $out['tabs'] = array_keys(BS_Settings::add_tab(['general' => 'Allgemein']));
        ob_start();
        BS_Settings::render();
        BS_Settings::render_connection_test();
        $out['connection_test'] = str_contains((string) ob_get_clean(), 'bs_connector_test');
        $out['rendered'] = $fieldIds($GLOBALS['bs_rendered']);
        ob_start();
        BS_Settings::secrets_notice();
        $out['secrets_notice'] = (string) ob_get_clean() !== '';
        $_POST = ['bs_connector_settings' => ['app_url' => 'https://evil.example', 'secret' => 'neu', 'alert_email' => 'a@example.test']];
        BS_Settings::save();
        $out['saved'] = $fieldIds($GLOBALS['bs_saved']);
        $out['post_after_save'] = $_POST['bs_connector_settings'];
        $out['stored_after_save'] = array_keys((array) get_option('bs_connector_settings', []));
        break;

    default:
        fwrite(STDERR, "Unbekanntes Szenario: {$scenario}\n");
        exit(2);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
