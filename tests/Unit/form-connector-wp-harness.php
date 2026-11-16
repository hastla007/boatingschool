<?php

use BoatingschoolFormConnector\Connector;
use BoatingschoolFormConnector\Privacy;

/**
 * Minimaler WordPress- und Form-Captain-Ersatz für die Tests des BoatingSchool Form-Connectors
 * (FormConnectorContractTest). Läuft in einem eigenen PHP-Prozess ohne Laravel, führt ein Szenario mit den echten
 * Plugin-Klassen aus und gibt Beobachtungen als JSON aus. Kein Test für echtes WordPress – nur für die Plugin-Logik
 * und den Connector-Vertrag v1 (Form Captain docs/CONNECTORS.md), im Stil der Contract-Assertions des
 * Referenz-Connectors.
 *
 * Aufruf: php form-connector-wp-harness.php <szenario> '<json-optionen>'
 */
[$scenario, $options] = [$argv[1] ?? '', json_decode($argv[2] ?? '{}', true) ?: []];

define('ABSPATH', __DIR__.'/');
foreach ($options['constants'] ?? [] as $name => $value) {
    define($name, $value);
}

$GLOBALS['fc_options'] = $options['stored'] ?? [];
$GLOBALS['fc_hooks'] = [];
$GLOBALS['fc_did'] = [];
$GLOBALS['fc_http'] = [];
$GLOBALS['fc_http_responses'] = $options['responses'] ?? [];
$GLOBALS['fc_actions'] = [];
$GLOBALS['fc_cron'] = [];
$GLOBALS['fc_notices'] = [];
$GLOBALS['fc_entries'] = $options['entries'] ?? [];
$GLOBALS['fc_caps'] = $options['caps'] ?? ['activate_plugins', 'manage_options'];

final class WP_Error
{
    public function __construct(private string $code = '', private string $message = '') {}

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}
function __($text, $domain = null)
{
    return $text;
}
function esc_html__($text, $domain = null)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_attr($text)
{
    return esc_html($text);
}
function current_user_can($cap)
{
    return in_array($cap, $GLOBALS['fc_caps'], true);
}
function get_option($name, $default = false)
{
    return $GLOBALS['fc_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['fc_options'][$name] = $value;
    $GLOBALS['fc_autoload'][$name] = $autoload;

    return true;
}
function delete_option($name)
{
    unset($GLOBALS['fc_options'][$name]);

    return true;
}
function home_url($path = '')
{
    return 'https://www.boatingschool.example'.$path;
}
function untrailingslashit($value)
{
    return rtrim((string) $value, '/\\');
}
function is_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function sanitize_text_field($value)
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $value)));
}
function sanitize_textarea_field($value)
{
    return trim(strip_tags((string) $value));
}
function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags);
}
function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['fc_hooks'][$hook][] = $callback;

    return true;
}
function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    return add_action($hook, $callback, $priority, $args);
}
function has_action($hook, $callback = false)
{
    return isset($GLOBALS['fc_hooks'][$hook]) && ($callback === false || in_array($callback, $GLOBALS['fc_hooks'][$hook], true));
}
function has_filter($hook, $callback = false)
{
    return has_action($hook, $callback);
}
function did_action($hook)
{
    return $GLOBALS['fc_did'][$hook] ?? 0;
}
function do_action($hook, ...$args)
{
    $GLOBALS['fc_did'][$hook] = ($GLOBALS['fc_did'][$hook] ?? 0) + 1;
    foreach ($GLOBALS['fc_hooks'][$hook] ?? [] as $callback) {
        $callback(...$args);
    }
}
function apply_filters($hook, $value, ...$args)
{
    foreach ($GLOBALS['fc_hooks'][$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}
function register_deactivation_hook($file, $callback) {}
function register_activation_hook($file, $callback) {}
function wp_next_scheduled($hook)
{
    return $GLOBALS['fc_cron'][$hook] ?? false;
}
function wp_schedule_single_event($time, $hook, $args = [])
{
    $GLOBALS['fc_cron'][$hook] = $time;

    return true;
}
function wp_clear_scheduled_hook($hook)
{
    unset($GLOBALS['fc_cron'][$hook]);
}
function wp_remote_request($url, $args)
{
    $GLOBALS['fc_http'][] = ['url' => $url] + $args;
    $next = array_shift($GLOBALS['fc_http_responses']) ?? ['status' => 500, 'body' => []];
    if (isset($next['error'])) {
        return new WP_Error('http_request_failed', $next['error']);
    }

    return ['response' => ['code' => $next['status']], 'body' => json_encode($next['body'] ?? []), 'headers' => $next['headers'] ?? []];
}
function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'];
}
function wp_remote_retrieve_body($response)
{
    return $response['body'];
}
function wp_remote_retrieve_header($response, $name)
{
    return $response['headers'][$name] ?? '';
}

// Weitere Kernfunktionen, die Kernel und Lizenzclient (lib/) beim Laden brauchen; ohne Lizenzserver und Lizenz.
function is_admin()
{
    return false;
}
function is_multisite()
{
    return false;
}
function plugin_basename($file)
{
    return basename(dirname($file)).'/'.basename($file);
}
function wp_doing_cron()
{
    return false;
}
function wp_doing_ajax()
{
    return false;
}
function get_site_option($name, $default = false)
{
    return get_option($name, $default);
}
function get_transient($key)
{
    return $GLOBALS['fc_options']['_transient_'.$key] ?? false;
}
function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['fc_options']['_transient_'.$key] = $value;

    return true;
}
function add_query_arg($args, $url = '')
{
    return $url.'?'.http_build_query((array) $args);
}
function admin_url($path = '')
{
    return 'https://www.boatingschool.example/wp-admin/'.$path;
}
function esc_url($url)
{
    return (string) $url;
}
function wp_kses_post($text)
{
    return (string) $text;
}
function get_current_screen()
{
    return null;
}
function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

// --- Form Captain: öffentliche API des Vertrags v1 (nur was der Connector nutzt) ---
if (! empty($options['form_captain'])) {
    eval('namespace FormCaptain\Integrations; interface ConnectorInterface {
        public function key(): string; public function label(): string; public function version(): string;
        public function contract(): string; public function capabilities(): array; public function requirements(): array;
        public function settings_schema(): array; public function register_actions(): void; }');
    define('FORM_CAPTAIN_VERSION', '1.0.0-dev');
    define('FORM_CAPTAIN_CONTRACT_VERSION', $options['contract'] ?? '1.1.0');
    function form_captain_contract_version(): string
    {
        return FORM_CAPTAIN_CONTRACT_VERSION;
    }
    function form_captain_register_action_v1(string $key, array $definition, callable $callback)
    {
        $GLOBALS['fc_actions'][$key] = ['definition' => $definition, 'callback' => $callback];

        return true;
    }
    function form_captain_get_entry_v1(int $entry_id, array $scopes = ['meta'])
    {
        return $GLOBALS['fc_entries'][$entry_id] ?? new WP_Error('forbidden', 'not allowed');
    }
}

/** Plugin in einen Ordner kopieren (mit oder ohne lib/) und laden – wie WordPress beim Einbinden. */
function fc_load_plugin(bool $withLib): string
{
    $source = dirname(__DIR__, 2).'/integrations/boatingschool-form-connector';
    $target = sys_get_temp_dir().'/bsfc-'.bin2hex(random_bytes(4));
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir("$from/$item") ? $copy("$from/$item", "$to/$item") : copy("$from/$item", "$to/$item");
        }
    };
    mkdir($target, 0777, true);
    foreach (['boatingschool-form-connector.php', 'config.php'] as $file) {
        copy("$source/$file", "$target/$file");
    }
    $copy("$source/src", "$target/src");
    if ($withLib && is_dir("$source/lib")) {
        $copy("$source/lib", "$target/lib");
    }
    require $target.'/boatingschool-form-connector.php';

    return $target;
}

function fc_load_classes(): void
{
    define('BOATINGSCHOOL_FORM_VERSION', '1.0.0');
    $dir = dirname(__DIR__, 2).'/integrations/boatingschool-form-connector/src';
    foreach (['Signature', 'Settings', 'Client', 'LeadPayload', 'Ledger', 'Privacy', 'Connector'] as $class) {
        require_once "$dir/$class.php";
    }
}

$out = [];
switch ($scenario) {
    case 'shape':
        fc_load_classes();
        $connector = new Connector;
        $connector->register_actions();
        $out = [
            'interfaces' => class_implements($connector),
            'key' => $connector->key(),
            'label' => $connector->label(),
            'version' => $connector->version(),
            'contract' => $connector->contract(),
            'capabilities' => $connector->capabilities(),
            'requirements' => $connector->requirements(),
            'settings_schema' => $connector->settings_schema(),
            'actions' => array_map(fn ($a) => $a['definition'], $GLOBALS['fc_actions']),
        ];
        break;

    case 'send':
        fc_load_classes();
        $connector = new Connector;
        $results = [];
        foreach ($options['jobs'] as $job) {
            try {
                $results[] = $connector->send_lead($job);
            } catch (Throwable $e) {
                $results[] = ['exception' => get_class($e)];
            }
        }
        $out = ['results' => $results, 'http' => $GLOBALS['fc_http'], 'options' => $GLOBALS['fc_options'], 'autoload' => $GLOBALS['fc_autoload'] ?? []];
        break;

    case 'map':
        fc_load_classes();
        $out = ['results' => array_map(fn ($c) => Connector::map_response($c['response'], $c['attempt'] ?? 1), $options['cases'])];
        break;

    case 'privacy':
        fc_load_classes();
        Privacy::register();
        foreach ($options['events'] as [$hook, $args]) {
            do_action($hook, ...$args);
        }
        $out = ['http' => $GLOBALS['fc_http'], 'options' => $GLOBALS['fc_options'], 'cron' => $GLOBALS['fc_cron']];
        break;

    case 'boot':
        // Plugin wie in WordPress laden, dann plugins_loaded und form_captain_loaded_v1 auslösen.
        $dir = fc_load_plugin((bool) ($options['with_lib'] ?? false));
        do_action('form_captain_loaded_v1', defined('FORM_CAPTAIN_CONTRACT_VERSION') ? FORM_CAPTAIN_CONTRACT_VERSION : '1');
        do_action('plugins_loaded');
        ob_start();
        do_action('admin_notices');
        $notices = ob_get_clean();
        $out = [
            'lib' => is_dir($dir.'/lib'),
            'connectors' => array_keys(apply_filters('form_captain_connectors_v1', [])),
            'notices' => $notices,
        ];
        break;
}

echo json_encode($out);
