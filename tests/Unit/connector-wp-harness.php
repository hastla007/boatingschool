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
$GLOBALS['bs_filters'] = $options['filters'] ?? [];
$GLOBALS['bs_user_meta'] = ($options['user_meta'] ?? []) + ['_bs_email_confirmed' => 'kunde@example.test'];
$GLOBALS['bs_queue'] = [];
$GLOBALS['bs_alerts'] = [];
$GLOBALS['bs_env'] = $options['env'] ?? [];
$GLOBALS['bs_deactivated'] = [];

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
final class WP_User
{
    public function __construct(public int $ID, public string $user_email) {}
}
/** Nur Kunde 7 existiert, mit bestätigter E-Mail-Adresse (E-Mail-Bestätigung vor der Verknüpfung). */
function get_userdata($id)
{
    return (int) $id === 7 ? new WP_User(7, 'kunde@example.test') : false;
}
function get_user_by($field, $value)
{
    return $field === 'email' && strtolower((string) $value) === 'kunde@example.test' ? get_userdata(7) : false;
}
function is_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function _n($single, $plural, $number, $domain = null)
{
    return $number === 1 ? $single : $plural;
}
function absint($value)
{
    return abs((int) $value);
}
function admin_url($path = '')
{
    return 'https://shop.test/wp-admin/'.$path;
}
function get_edit_user_link($id)
{
    return admin_url('user-edit.php?user_id='.$id);
}
function add_query_arg(array $args, string $url)
{
    return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($args);
}
/** Nonces: gültig ist genau „valid-<Aktion>“. */
function wp_nonce_url($url, $action)
{
    return add_query_arg(['_wpnonce' => 'valid-'.$action], $url);
}
/** Abbruch statt exit/die, damit das Szenario weiterläuft. */
final class BS_Harness_Stop extends Exception {}
function check_admin_referer($action)
{
    if (($_REQUEST['_wpnonce'] ?? '') !== 'valid-'.$action) {
        throw new BS_Harness_Stop('nonce');
    }

    return 1;
}
function wp_die($message = '', $code = 500)
{
    $GLOBALS['bs_die_message'] = $message;
    throw new BS_Harness_Stop('die:'.$code);
}
/** Umgebung für die Mindestanforderungen (BS_Requirements): Versionen aus den Optionen. */
function bs_connector_php_version()
{
    return $GLOBALS['bs_env']['php'] ?? PHP_VERSION;
}
function get_bloginfo($what)
{
    return $what === 'version' ? ($GLOBALS['bs_env']['wordpress'] ?? '7.1.4') : '';
}
function plugin_basename($file)
{
    return basename(dirname($file)).'/'.basename($file);
}
function deactivate_plugins($plugins)
{
    $GLOBALS['bs_deactivated'][] = $plugins;
}
function wp_safe_redirect($url)
{
    $GLOBALS['bs_redirect'] = $url;
    throw new BS_Harness_Stop('redirect');
}
function wp_get_current_user()
{
    return get_userdata(get_current_user_id());
}
/** Nutzer-Metadaten von Kunde 7 (Optionen 'user_meta'), beschreibbar. */
function get_user_meta($id, $key, $single = false)
{
    return $GLOBALS['bs_user_meta'][$key] ?? '';
}
function update_user_meta($id, $key, $value)
{
    $GLOBALS['bs_user_meta'][$key] = $value;

    return true;
}
function delete_user_meta($id, $key)
{
    unset($GLOBALS['bs_user_meta'][$key]);

    return true;
}
/** Hooks nur aufzeichnen (Hook => letzter Rückruf; alle Rückrufe in bs_hook_list). */
function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['bs_hooks'][$hook] = $callback;
    $GLOBALS['bs_hook_list'][$hook][] = $callback;

    return true;
}
function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    return add_action($hook, $callback, $priority, $args);
}
function wp_json_encode($data)
{
    return json_encode($data);
}
/** Action Scheduler nur auf Wunsch (Option 'action_scheduler'); Aufgaben landen in bs_queue. */
if (! empty($options['action_scheduler'])) {
    function as_enqueue_async_action($hook, $args = [], $group = '')
    {
        $GLOBALS['bs_queue'][] = [$hook, $args];

        return count($GLOBALS['bs_queue']);
    }
    function as_schedule_single_action($timestamp, $hook, $args = [], $group = '')
    {
        $GLOBALS['bs_queue'][] = [$hook, $args];
        $GLOBALS['bs_queue_meta'][] = ['delay' => $timestamp - time(), 'group' => $group];

        return count($GLOBALS['bs_queue']);
    }
}
/** Wie WordPress: option_{name} für gespeicherte Werte, default_option_{name} sonst. */
function get_option($name, $default = false)
{
    $stored = array_key_exists($name, $GLOBALS['bs_options']);
    $value = $stored ? $GLOBALS['bs_options'][$name] : $default;
    foreach ($GLOBALS['bs_hook_list'][($stored ? 'option_' : 'default_option_').$name] ?? [] as $callback) {
        $value = $callback($value);
    }

    return $value;
}
function update_option($name, $value)
{
    $GLOBALS['bs_options'][$name] = $value;

    return true;
}
function delete_option($name)
{
    unset($GLOBALS['bs_options'][$name]);

    return true;
}
/** Filter aus den Optionen ('filters' => [hook => Wert]) ersetzen den Wert. */
function apply_filters($hook, $value)
{
    return array_key_exists($hook, $GLOBALS['bs_filters']) ? $GLOBALS['bs_filters'][$hook] : $value;
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
/**
 * Wie WC_Admin_Settings::save_fields() für Felder der Form option[key]: nur die
 * übergebenen Felder werden geschrieben, Kästchen als yes/no, fehlende Werte leer.
 */
function woocommerce_update_options($fields)
{
    $GLOBALS['bs_saved'] = $fields;
    $values = (array) get_option('bs_connector_settings', []);
    foreach ($fields as $field) {
        if (! preg_match('/^bs_connector_settings\[(\w+)\]$/', (string) ($field['id'] ?? ''), $m)) {
            continue;
        }
        $raw = $_POST['bs_connector_settings'][$m[1]] ?? null;
        $values[$m[1]] = $field['type'] === 'checkbox' ? (in_array($raw, ['1', 'yes'], true) ? 'yes' : 'no') : (string) $raw;
    }
    update_option('bs_connector_settings', $values);
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
        // Text statt Zahl: 200 mit diesem Kontostatus ohne Einkauf (z. B. pending_review).
        if (is_string($status)) {
            return ['status' => 200, 'body' => ['data' => ['status' => $status, 'can_purchase' => false, 'tenant' => null, 'deliverable_course_codes' => []]], 'error' => null];
        }
        $body = $status === 200 ? ['data' => ['status' => 'linked', 'can_purchase' => true, 'tenant' => ['name' => 'Segelschule Möwe'], 'deliverable_course_codes' => ['SRC']]] : null;

        return ['status' => $status, 'body' => $body, 'error' => $status === 200 ? null : 'HTTP '.$status];
    }
}

/** Ersatz für die Alarm-Mail. */
final class BS_Orders
{
    public static function alert(string $subject, string $message): void
    {
        $GLOBALS['bs_alerts'][] = $subject.': '.$message;
    }
}

/** WP-CLI-Ersatz: Meldungen aufzeichnen; error() bricht ab wie das echte (Exit-Status 1). */
final class WP_CLI
{
    public static function log($message): void
    {
        $GLOBALS['bs_cli'][] = ['log', $message];
    }

    public static function success($message): void
    {
        $GLOBALS['bs_cli'][] = ['success', $message];
    }

    public static function warning($message): void
    {
        $GLOBALS['bs_cli'][] = ['warning', $message];
    }

    public static function error($message): void
    {
        $GLOBALS['bs_cli'][] = ['error', $message];
        throw new BS_Harness_Stop('cli_error');
    }
}
if (! empty($options['woocommerce'])) {
    final class WooCommerce {}
}

$includes = dirname(__DIR__, 2).'/integrations/woocommerce-connector/includes/';
require $includes.'class-bs-requirements.php';
require $includes.'class-bs-log.php';
require $includes.'class-bs-settings.php';
require $includes.'class-bs-vat.php';
require $includes.'class-bs-email-verification.php';
require $includes.'class-bs-accounts.php';
require $includes.'class-bs-cli.php';

$out = [];
$calls = static fn (): int => count($GLOBALS['bs_api_calls']);
$advance = static function (int $seconds): void {
    $GLOBALS['bs_clock'] += $seconds;
};
// Die jetzt eingeplanten Abgleiche ausführen (wie ein Lauf von Action Scheduler);
// dabei neu eingeplante Wiederholungen warten auf den nächsten Lauf.
$runQueue = static function (): void {
    [$jobs, $GLOBALS['bs_queue']] = [$GLOBALS['bs_queue'], []];
    foreach ($jobs as $job) {
        BS_Accounts::sync_account(...$job[1]);
    }
};
// Aufruf, der mit Weiterleitung, wp_die oder ungültiger Nonce endet: Grund des Abbruchs.
$stopped = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (BS_Harness_Stop $stop) {
        return $stop->getMessage();
    }

    return null;
};
$noticesFor = static function (?string $url): string {
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $_GET);
    ob_start();
    BS_Accounts::resync_notices();

    return (string) ob_get_clean();
};
// Wie CartCheckoutUtils::get_company_field_visibility() (WooCommerce 11.3): ohne Option
// übernimmt die Block-Kasse ihre Standards und speichert „hidden“, sonst gilt „optional“.
$wcCompanyVisibility = static function () use ($options): string {
    $value = get_option('woocommerce_checkout_company_field');
    if ($value) {
        return $value;
    }
    if (! empty($options['checkout_block_default'])) {
        update_option('woocommerce_checkout_company_field', 'hidden');

        return get_option('woocommerce_checkout_company_field', 'hidden');
    }

    return 'optional';
};
// Rechnungsfelder der klassischen Kasse wie WC_Countries::get_default_address_fields()
// und get_address_fields('', 'billing_') (WooCommerce 11.3), nur Name und Firma.
$wcClassicBillingFields = static function () use ($wcCompanyVisibility): array {
    $fields = [
        'first_name' => ['required' => true],
        'last_name' => ['required' => true],
        'company' => ['required' => $wcCompanyVisibility() === 'required'],
    ];
    if ($wcCompanyVisibility() === 'hidden') {
        unset($fields['company']);
    }
    $billing = [];
    foreach ($fields as $key => $field) {
        $billing['billing_'.$key] = $field;
    }

    return $billing;
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

    case 'status_not_purchasable':
        // In Prüfung: 60 s gemerkt (nicht 5 Minuten), danach frisch -- z. B. nach der Freigabe.
        $out['first'] = BS_Accounts::status(7)['status'] ?? null;
        $advance(59);
        $out['within_minute'] = BS_Accounts::status(7)['status'] ?? null;
        $out['calls_within_minute'] = $calls();
        $advance(2);
        $after = BS_Accounts::status(7);
        $out['after_minute'] = $after['status'] ?? null;
        $out['can_purchase_after_minute'] = $after['can_purchase'] ?? null;
        $out['calls_after_minute'] = $calls();
        // Freigeschaltet: wieder 5 Minuten.
        $advance(299);
        BS_Accounts::status(7);
        $out['calls_linked_cached'] = $calls();
        break;

    case 'company_field':
        BS_Settings::init();
        BS_Accounts::init();
        $out['visibility'] = $wcCompanyVisibility();
        $out['billing_fields'] = $wcClassicBillingFields();
        $out['stored'] = $GLOBALS['bs_options']['woocommerce_checkout_company_field'] ?? null;
        // Alle Backend-Hinweise der Plugin-Klassen.
        ob_start();
        foreach ($GLOBALS['bs_hook_list']['admin_notices'] ?? [] as $callback) {
            $callback();
        }
        $out['admin_notices'] = (string) ob_get_clean();
        BS_Settings::render();
        $out['settings_intro'] = implode(' ', array_map(static fn ($f) => (string) ($f['desc'] ?? ''), array_filter((array) $GLOBALS['bs_rendered'], static fn ($f) => ($f['id'] ?? '') === 'bs_connector_section')));
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
        $_POST = ['bs_connector_settings' => $options['post'] ?? ['app_url' => 'https://evil.example', 'secret' => 'neu', 'alert_email' => 'a@example.test']];
        BS_Settings::save();
        $out['saved'] = $fieldIds($GLOBALS['bs_saved']);
        $out['post_after_save'] = $_POST['bs_connector_settings'];
        $out['stored_after_save'] = array_keys((array) get_option('bs_connector_settings', []));
        $out['settings_after_save'] = (array) get_option('bs_connector_settings', []);
        $out['options_after_save'] = array_keys($GLOBALS['bs_options']);
        break;

    case 'upgrade':
        // Update-Schritt beim Laden (zweimal: läuft je Version nur einmal), dann
        // wirksame Geheimnisse, Einstellung, Hinweis und Formular.
        BS_Settings::maybe_upgrade($options['version'] ?? '1.4.0');
        $first = (array) get_option('bs_connector_settings', []);
        BS_Settings::maybe_upgrade($options['version'] ?? '1.4.0');
        $out['settings'] = (array) get_option('bs_connector_settings', []);
        $out['settings_unchanged_by_second_run'] = $out['settings'] === $first;
        $out['version'] = get_option('bs_connector_version');
        $out['kept_flag'] = get_option('bs_connector_db_secrets_kept');
        $out['allow_db_secrets'] = BS_Settings::allow_db_secrets();
        $settings = BS_Settings::get();
        $out['secret'] = $settings['secret'];
        $out['inbound_secret'] = $settings['inbound_secret'];
        ob_start();
        BS_Settings::secrets_notice();
        $out['notice'] = (string) ob_get_clean();
        BS_Settings::render();
        $out['rendered'] = $fieldIds($GLOBALS['bs_rendered']);
        $out['rendered_texts'] = implode(' ', array_map(static fn ($f) => (string) ($f['desc'] ?? ''), (array) $GLOBALS['bs_rendered']));
        break;

    case 'account_sync':
        // Ohne Action Scheduler wird sofort gesendet; gezählt werden die PATCH-Aufrufe.
        BS_Accounts::queue_update(7);
        $out['calls_first_save'] = $calls();
        $out['hash_after_first'] = (string) get_user_meta(7, '_bs_account_sync_hash', true);
        // WooCommerce speichert den Kunden erneut (z. B. bei einer Bestellung).
        BS_Accounts::queue_update(7);
        $out['calls_unchanged_save'] = $calls();
        // Nicht abgeglichenes Feld.
        update_user_meta(7, 'billing_phone', '0170 1234567');
        BS_Accounts::queue_update(7);
        $out['calls_unsynced_field'] = $calls();
        // Abgeglichenes Feld.
        update_user_meta(7, 'billing_company', 'Segelschule Kranich');
        BS_Accounts::queue_update(7);
        $out['calls_synced_field'] = $calls();
        $out['hash_changed'] = $out['hash_after_first'] !== (string) get_user_meta(7, '_bs_account_sync_hash', true);
        // Neue USt-IdNr. in anderer Schreibweise, aber gleich aufbereitet: kein Aufruf.
        update_user_meta(7, 'billing_vat_id', 'DE 811 191 002');
        BS_Accounts::queue_update(7);
        $out['calls_vat_added'] = $calls();
        update_user_meta(7, 'billing_vat_id', 'de811191002');
        BS_Accounts::queue_update(7);
        $out['calls_vat_same_normalized'] = $calls();
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        break;

    case 'account_sync_wc_save':
        // Bestellung mit geänderter Firma: WooCommerce feuert profile_update vor dem
        // Schreiben der Rechnungsdaten, woocommerce_update_customer danach.
        BS_Accounts::queue_update(7);
        $out['calls_first_save'] = $calls();
        BS_Accounts::queue_update(7); // profile_update, noch alter Stand
        update_user_meta(7, 'billing_company', 'Segelschule Kranich');
        BS_Accounts::queue_update(7); // woocommerce_update_customer
        $out['calls_after_order'] = $calls();
        // Hooks aus init(): woocommerce_update_customer gleicht ebenfalls ab.
        $GLOBALS['bs_hooks'] = [];
        BS_Accounts::init();
        $out['update_hooks'] = array_keys(array_filter($GLOBALS['bs_hooks'], static fn ($cb) => $cb === [BS_Accounts::class, 'queue_update']));
        break;

    case 'account_sync_failure':
        // Mit Action Scheduler: Fehler -> Wiederholung eingeplant, Prüfsumme bleibt leer.
        BS_Accounts::queue_update(7);
        $out['queued'] = count($GLOBALS['bs_queue']);
        $runQueue();
        $out['calls_after_failure'] = $calls();
        $out['hash_after_failure'] = (string) get_user_meta(7, '_bs_account_sync_hash', true);
        $out['alerts_after_failure'] = $GLOBALS['bs_alerts'];
        $out['retry_queued'] = $GLOBALS['bs_queue'];
        // Erneut gespeichert, bevor die Wiederholung lief: wird ebenfalls eingeplant.
        BS_Accounts::queue_update(7);
        $out['queued_after_failure_save'] = count($GLOBALS['bs_queue']);
        // Wiederholung sendet und setzt die Prüfsumme; die zweite Aufgabe entfällt.
        $runQueue();
        $out['calls_after_retry'] = $calls();
        $out['hash_after_retry'] = (string) get_user_meta(7, '_bs_account_sync_hash', true) !== '';
        BS_Accounts::queue_update(7);
        $out['queued_after_success_save'] = count($GLOBALS['bs_queue']);
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        break;

    case 'account_sync_twice_queued':
        // profile_update und woocommerce_customer_save_address in einer Anfrage:
        // zwei Aufgaben, aber nur ein Aufruf.
        BS_Accounts::queue_update(7);
        BS_Accounts::queue_update(7);
        $out['queued'] = count($GLOBALS['bs_queue']);
        $runQueue();
        $out['calls'] = $calls();
        // Registrierung setzt die Prüfsumme ebenfalls: das folgende Speichern sendet nichts.
        unset($GLOBALS['bs_user_meta']['_bs_account_sync_hash']);
        BS_Accounts::sync_account('register', 7);
        BS_Accounts::queue_update(7);
        $out['queued_after_register'] = count($GLOBALS['bs_queue']);
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        break;

    case 'account_resync':
        // Abgeglichen (Prüfsumme gesetzt), Speichern ohne Änderung sendet nichts ...
        BS_Accounts::queue_update(7);
        $runQueue();
        $hash = (string) get_user_meta(7, '_bs_account_sync_hash', true);
        BS_Accounts::queue_update(7);
        $out['queued_unchanged_save'] = count($GLOBALS['bs_queue']);
        // ... der ausdrückliche Abgleich schon.
        $out['resync_queued'] = BS_Accounts::queue_resync(7);
        $out['resync_jobs'] = $GLOBALS['bs_queue'];
        $runQueue();
        $out['calls_after_resync'] = $calls();
        $out['hash_unchanged'] = $hash !== '' && $hash === (string) get_user_meta(7, '_bs_account_sync_hash', true);
        // Fehlschlag: die Wiederholung behält force und sendet trotz gleicher Prüfsumme.
        BS_Accounts::queue_resync(7);
        $runQueue();
        $out['retry_jobs'] = $GLOBALS['bs_queue'];
        $runQueue();
        $out['calls_after_retry'] = $calls();
        $out['hash_after_retry'] = (string) get_user_meta(7, '_bs_account_sync_hash', true) === $hash;
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        break;

    case 'admin_resync':
        // Profilseite, dann Klick auf den Knopf (Anfrage aus den Optionen), dann Hinweis.
        $GLOBALS['bs_hooks'] = [];
        BS_Accounts::init();
        $out['profile_hooks'] = [$GLOBALS['bs_hooks']['show_user_profile'] ?? null, $GLOBALS['bs_hooks']['edit_user_profile'] ?? null];
        ob_start();
        BS_Accounts::render_resync_profile(get_userdata(7));
        $out['profile'] = (string) ob_get_clean();
        $_GET = $_REQUEST = $options['request'] ?? [];
        $out['stop'] = $stopped([BS_Accounts::class, 'handle_admin_resync']);
        $out['redirect'] = $GLOBALS['bs_redirect'] ?? null;
        $out['queue'] = $GLOBALS['bs_queue'];
        $out['notice'] = $noticesFor($out['redirect']);
        $runQueue();
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        break;

    case 'admin_resync_bulk':
        $out['bulk_actions'] = array_keys(BS_Accounts::resync_bulk_action(['delete' => 'Löschen']));
        $_GET = $_REQUEST = $options['request'] ?? [];
        $redirect = null;
        $out['stop'] = $stopped(static function () use (&$redirect) {
            $redirect = BS_Accounts::handle_resync_bulk_action('https://shop.test/wp-admin/users.php', 'bs_connector_account_resync', ['7', '999']);
        });
        $out['other_action'] = BS_Accounts::handle_resync_bulk_action('users.php', 'delete', ['7']);
        $out['redirect'] = $redirect;
        $out['queue'] = $GLOBALS['bs_queue'];
        $out['notice'] = $noticesFor($redirect);
        break;

    case 'cli_resync':
        $GLOBALS['bs_cli'] = [];
        $out['stop'] = $stopped(static fn () => (new BS_CLI)->resync($options['args'] ?? [], []));
        $out['cli'] = $GLOBALS['bs_cli'];
        $out['api_calls'] = $GLOBALS['bs_api_calls'];
        $out['queue'] = $GLOBALS['bs_queue'];
        $out['hash_set'] = (string) get_user_meta(7, '_bs_account_sync_hash', true) !== '';
        break;

    case 'requirements':
        // Mindestanforderungen: Aktivierung (Abbruch mit Hinweis), Hinweise im
        // Backend (Anforderungen, Datenbank) und Verschieben eingeplanter Aufgaben.
        $out['problems'] = BS_Requirements::current_problems();
        $out['can_run'] = BS_Requirements::can_run(bs_connector_php_version());
        $out['activation_stop'] = $stopped(static function () {
            BS_Requirements::abort_activation(BS_Requirements::current_problems(), '/srv/wp-content/plugins/woocommerce-connector/boatingschool-connector.php');
        });
        $out['activation_message'] = $GLOBALS['bs_die_message'] ?? null;
        $out['deactivated'] = $GLOBALS['bs_deactivated'];
        $GLOBALS['wpdb'] = new class($options['db'] ?? '9.7.0')
        {
            public function __construct(private string $info) {}

            public function db_server_info()
            {
                return $this->info;
            }
        };
        BS_Requirements::register_notices();
        ob_start();
        foreach ($GLOBALS['bs_hook_list']['admin_notices'] ?? [] as $callback) {
            $callback();
        }
        $out['admin_notices'] = (string) ob_get_clean();
        break;

    case 'requirements_defer':
        BS_Requirements::defer_scheduled_actions();
        $out['hooks'] = array_keys($GLOBALS['bs_hook_list']);
        $GLOBALS['bs_hook_list']['bs_connector_deliver_item'][0](12, 34, 2);
        $GLOBALS['bs_hook_list']['bs_connector_void_item'][0](12, 34, 1, 'k', 1);
        $out['queue'] = $GLOBALS['bs_queue'];
        $out['queue_meta'] = $GLOBALS['bs_queue_meta'] ?? [];
        break;

    case 'uninstall':
        define('WP_UNINSTALL_PLUGIN', true);
        $GLOBALS['bs_deleted_meta'] = [];
        function delete_metadata($type, $object_id, $key, $value = '', $all = false)
        {
            $GLOBALS['bs_deleted_meta'][] = [$type, $object_id, $key, $all];

            return true;
        }
        $GLOBALS['wpdb'] = new class
        {
            public $options = 'wp_options';

            public function query($sql)
            {
                return 0;
            }
        };
        require dirname(__DIR__, 2).'/integrations/woocommerce-connector/uninstall.php';
        $out['deleted_meta'] = $GLOBALS['bs_deleted_meta'];
        break;

    default:
        fwrite(STDERR, "Unbekanntes Szenario: {$scenario}\n");
        exit(2);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
