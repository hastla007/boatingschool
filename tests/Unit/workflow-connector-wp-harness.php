<?php

/**
 * Minimaler WordPress- und Workflow-Captain-Ersatz für Tests des Plugins
 * integrations/boatingschool-workflow-connector (WorkflowConnectorPluginTest):
 * eigener PHP-Prozess ohne Laravel, echte Plugin-Klassen, Beobachtungen als
 * JSON. Kein Test für echtes WordPress oder echtes Workflow Captain -- die
 * Ersatzklassen bilden nur deren öffentliche Schnittstellen nach (Vertrag v1).
 *
 * Aufruf: php workflow-connector-wp-harness.php <szenario> '<json-optionen>'
 */

namespace WorkflowCaptain\Adapter {
    interface Adapter
    {
        public function product(): string;

        public function core_version(): string;

        public function contract_version(): ?string;

        public function types(): array;

        public function needs_reread(string $type): bool;

        public function reread(array $envelope): bool;
    }
}

namespace WorkflowCaptain\Engine {
    interface Action
    {
        public function key(): string;

        public function label(): string;

        public function capability(): ?string;

        public function idempotent(): bool;

        public function validate_config(array $config): array;

        public function execute(ActionContext $context): array;
    }

    final class ActionContext
    {
        public function __construct(
            public readonly array $envelope,
            public readonly array $config,
            public readonly string $idempotency_key,
            public readonly int $workflow_id = 1,
            public readonly int $run_id = 1,
            public readonly int $step_id = 1,
            public readonly int $authorized_by_user_id = 1,
        ) {}
    }
}

namespace WorkflowCaptain\Consumer {
    use WorkflowCaptain\Adapter\Adapter;

    class TransientFailure extends \RuntimeException
    {
        public function __construct(public readonly string $reason)
        {
            parent::__construct($reason);
        }
    }

    class PermanentFailure extends \RuntimeException
    {
        public function __construct(public readonly string $reason, public readonly bool $pause = false)
        {
            parent::__construct($reason);
        }
    }

    /** Wie Consumer::capture(): Doppel über den Idempotenzschlüssel aus Typ, Gegenstand und Diskriminator. */
    final class Consumer
    {
        public array $captured = [];

        private array $keys = [];

        public function capture(Adapter $adapter, array $event): string
        {
            $key = hash('sha256', implode('|', ['1', 1, $event['type'], $event['subject']['type'], $event['subject']['id'], $event['discriminator']]));
            if (isset($this->keys[$key])) {
                return 'duplicate';
            }
            $result = $GLOBALS['bsw_capture_result'] ?? 'captured';
            if ($result !== 'captured') {
                return $result; // abgelehnt: nichts gespeichert
            }
            $this->keys[$key] = true;
            $this->captured[] = ['product' => $adapter->product(), 'event' => $event];

            return $result;
        }
    }
}

namespace WorkflowCaptain {
    final class Plugin
    {
        private static ?Plugin $instance = null;

        public Consumer\Consumer $consumer;

        public static function instance(): self
        {
            if (! self::$instance) {
                self::$instance = new self;
                self::$instance->consumer = new Consumer\Consumer;
            }

            return self::$instance;
        }

        public function consumer(): Consumer\Consumer
        {
            return $this->consumer;
        }
    }
}

namespace {
    use WorkflowCaptain\Adapter\Adapter;
    use WorkflowCaptain\Consumer\PermanentFailure;
    use WorkflowCaptain\Consumer\TransientFailure;
    use WorkflowCaptain\Engine\Action;
    use WorkflowCaptain\Engine\ActionContext;
    use WorkflowCaptain\Plugin;

    [$scenario, $options] = [$argv[1] ?? '', json_decode($argv[2] ?? '{}', true) ?: []];

    define('ABSPATH', __DIR__.'/');
    define('BSW_CONNECTOR_VERSION', '0.1.0');
    define('WORKFLOW_CAPTAIN_CONTRACT_VERSION', '0.1.0');
    foreach ($options['constants'] ?? [] as $name => $value) {
        define($name, $value);
    }

    $GLOBALS['bsw_now'] = 1_700_000_000;
    $GLOBALS['bsw_transients'] = [];
    $GLOBALS['bsw_options'] = $options['stored'] ?? [];
    $GLOBALS['bsw_requests'] = [];
    $GLOBALS['bsw_responses'] = $options['responses'] ?? [];
    $GLOBALS['bsw_capture_result'] = $options['capture_result'] ?? null;

    function get_transient($key)
    {
        $t = $GLOBALS['bsw_transients'][$key] ?? null;

        return $t !== null && ($t['expires'] === 0 || $t['expires'] > $GLOBALS['bsw_now']) ? $t['value'] : false;
    }
    function set_transient($key, $value, $ttl = 0)
    {
        $GLOBALS['bsw_transients'][$key] = ['value' => $value, 'expires' => $ttl ? $GLOBALS['bsw_now'] + $ttl : 0];

        return true;
    }
    function get_option($name, $default = false)
    {
        return $GLOBALS['bsw_options'][$name] ?? $default;
    }
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['bsw_options'][$name] = $value;

        return true;
    }
    function delete_option($name)
    {
        unset($GLOBALS['bsw_options'][$name]);

        return true;
    }
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        $GLOBALS['bsw_hooks'][$hook][] = $callback;
    }
    function do_action($hook, ...$args)
    {
        foreach ($GLOBALS['bsw_hooks'][$hook] ?? [] as $callback) {
            $callback(...$args);
        }
    }
    function __($text, $domain = null)
    {
        return $text;
    }
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
    final class WP_Error {}
    function wp_remote_post($url, $args)
    {
        $GLOBALS['bsw_requests'][] = ['url' => $url, 'headers' => $args['headers'], 'body' => $args['body'], 'timeout' => $args['timeout']];
        $next = array_shift($GLOBALS['bsw_responses']);
        if ($next === null) {
            return new WP_Error;
        }

        return ['response' => ['code' => $next[0]], 'body' => json_encode($next[1] ?? [])];
    }
    function wp_remote_retrieve_response_code($r)
    {
        return $r['response']['code'];
    }
    function wp_remote_retrieve_body($r)
    {
        return $r['body'];
    }

    $dir = dirname(__DIR__, 2).'/integrations/boatingschool-workflow-connector/includes/';
    foreach (['signature', 'manifest', 'payload-mapper', 'settings', 'license', 'diagnostics', 'app-client', 'workflow', 'receiver'] as $file) {
        require_once $dir.'class-bsw-'.$file.'.php';
    }
    BSW_License::use_checker(fn () => $options['license'] ?? BSW_License::VALID);

    /** Fake ConnectorRegistry::add(): nimmt an, wenn product() und key passen. */
    final class Fake_Connector_Registry
    {
        public ?array $manifest = null;

        public function add(array $manifest, Adapter $adapter): bool
        {
            $this->manifest = $manifest;

            return $adapter->product() === $manifest['key'];
        }
    }
    final class Fake_Action_Registry
    {
        public array $actions = [];

        public function add(Action $action): bool
        {
            $this->actions[$action->key()] = $action;

            return true;
        }
    }

    function bsw_register(): array
    {
        $registry = new Fake_Connector_Registry;
        do_action('workflow_captain_register_connectors_v1', $registry);
        $actions = new Fake_Action_Registry;
        do_action('workflow_captain_actions_v1', $actions);

        return [$registry, $actions];
    }

    /** Signiertes Ereignis wie App\Workflow\WorkflowDispatcher::send(). */
    function bsw_event(array $event, string $key = 'app-workflow', string $secret = 'inbound-secret-0123456789', ?int $ts = null, ?string $nonce = null): array
    {
        $body = json_encode(['op' => 'event', 'contract_version' => $event['_contract'] ?? '1.0.0', 'event' => array_diff_key($event, ['_contract' => 1])]);
        $ts = (string) ($ts ?? $GLOBALS['bsw_now']);
        $nonce ??= bin2hex(random_bytes(16));

        return [[
            BSW_Signature::HEADER_KEY => $key, BSW_Signature::HEADER_TIMESTAMP => $ts, BSW_Signature::HEADER_NONCE => $nonce,
            BSW_Signature::HEADER_SIGNATURE => BSW_Signature::sign($secret, $ts, $nonce, $body),
        ], $body];
    }

    BSW_Workflow::init();
    $out = [];
    switch ($scenario) {
        case 'receive':
            // Jede Anfrage: [event-Überschreibungen, key, secret, ts-Versatz, nonce-Wiederverwendung]
            [$registry] = bsw_register();
            $out['registered'] = BSW_Workflow::registered();
            $out['manifest_key'] = $registry->manifest['key'] ?? null;
            $base = $options['event'];
            $last = null;
            foreach ($options['requests'] as $r) {
                if (! empty($r['reuse']) && $last) {
                    [$headers, $body] = $last;
                } else {
                    [$headers, $body] = bsw_event(($r['event'] ?? []) + $base, $r['key'] ?? 'app-workflow', $r['secret'] ?? 'inbound-secret-0123456789', $GLOBALS['bsw_now'] + ($r['skew'] ?? 0));
                }
                if (isset($r['tamper'])) {
                    $body .= ' ';
                }
                $last = [$headers, $body];
                $out['responses'][] = BSW_Receiver::handle($headers, $body, $GLOBALS['bsw_now']);
            }
            $out['captured'] = Plugin::instance()->consumer()->captured;
            $out['diagnostics'] = BSW_Diagnostics::get();
            break;

        case 'action':
            [, $actions] = bsw_register();
            $action = $actions->actions[$options['action']];
            $out['validate'] = $action->validate_config($options['config']);
            $out['capability'] = $action->capability();
            $out['idempotent'] = $action->idempotent();
            try {
                $out['result'] = $action->execute(new ActionContext($options['envelope'], $options['config'], $options['idempotency_key']));
            } catch (TransientFailure $e) {
                $out['transient'] = $e->reason;
            } catch (PermanentFailure $e) {
                $out['permanent'] = $e->reason;
                $out['pause'] = $e->pause;
            }
            $out['requests'] = array_map(function ($r) {
                $h = $r['headers'];
                $r['signature_valid'] = BSW_Signature::verify('outbound-secret-0123456789', $h[BSW_Signature::HEADER_TIMESTAMP], $h[BSW_Signature::HEADER_NONCE], $r['body'], $h[BSW_Signature::HEADER_SIGNATURE]);
                $r['body'] = json_decode($r['body'], true);

                return $r;
            }, $GLOBALS['bsw_requests']);
            $out['diagnostics'] = BSW_Diagnostics::get();
            break;

        case 'handshake':
            $out['handshake'] = BSW_App_Client::handshake($GLOBALS['bsw_now'], '0.1.0');
            $out['requests'] = array_map(fn ($r) => ['url' => $r['url'], 'body' => json_decode($r['body'], true)], $GLOBALS['bsw_requests']);
            $out['diagnostics'] = BSW_Diagnostics::get();
            break;

        case 'test_event':
            require_once $dir.'class-bsw-admin.php';
            if (! empty($options['register'])) {
                bsw_register();
            }
            $out['result'] = BSW_Admin::send_test_event($GLOBALS['bsw_now']);
            $out['status'] = BSW_Admin::status();
            $out['captured'] = Plugin::instance()->consumer()->captured;
            break;
    }

    echo json_encode($out);
}
