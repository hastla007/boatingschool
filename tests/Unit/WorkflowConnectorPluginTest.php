<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WordPress-Plugin integrations/boatingschool-workflow-connector (WF-CUST1-05,
 * -08, -09) gegen einen WordPress- und Workflow-Captain-Ersatz in einem
 * eigenen Prozess (workflow-connector-wp-harness.php): Anmeldung mit
 * Manifest, Ereignis-Eingang mit Signatur, Zeitfenster, Replay und Doppel,
 * Lizenz (fail-closed), Freigabe, Aktionen mit Idempotenz und Fehlerarten,
 * Handshake, Testereignis und Diagnose ohne Werte.
 *
 * Nicht geprüft: echtes WordPress (REST-Routing, Transients in der
 * Datenbank) und echtes Workflow Captain -- siehe docs/workflow/README.md.
 */
class WorkflowConnectorPluginTest extends TestCase
{
    private const TENANT = '11111111-2222-4333-8444-555555555555';

    private const BATCH = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private const CONSTANTS = [
        'BSW_CONNECTOR_APP_URL' => 'https://lernen.example.test',
        'BSW_CONNECTOR_KEY_ID' => 'workflow',
        'BSW_CONNECTOR_SECRET' => 'outbound-secret-0123456789',
        'BSW_CONNECTOR_INBOUND_KEY_ID' => 'app-workflow',
        'BSW_CONNECTOR_INBOUND_SECRET' => 'inbound-secret-0123456789',
    ];

    private const RELEASED = ['workflow_captain_connectors' => ['boatingschool-connector' => ['enabled' => true]]];

    private function harness(string $scenario, array $options = []): array
    {
        $options += ['constants' => self::CONSTANTS];
        $process = new Process([PHP_BINARY, __DIR__.'/workflow-connector-wp-harness.php', $scenario, json_encode($options)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function event(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0f1e2d3c-4b5a-5968-8776-655443322110', 'type' => 'code_batch.delivered', 'version' => 1, 'occurred_at' => '2026-11-16T10:00:00Z',
            'scope' => 'tenant', 'tenant_id' => self::TENANT, 'subject' => ['type' => 'code_batch', 'id' => self::BATCH], 'correlation_id' => 'order:abc',
            'data' => ['batch_id' => self::BATCH, 'tenant_id' => self::TENANT, 'order_id' => '7001', 'order_line_id' => '3', 'kind' => 'course',
                'quantity' => 2, 'course_key' => 'SBF-SEE', 'code' => 'GEHEIM-1234', 'email' => 'pia@example.test'],
        ];
    }

    private function receive(array $requests, array $options = []): array
    {
        return $this->harness('receive', $options + ['event' => $this->event(), 'requests' => $requests, 'stored' => self::RELEASED]);
    }

    public function test_events_are_captured_once_with_signature_window_replay_and_duplicate_protection(): void
    {
        $out = $this->receive([
            [],                                                     // angenommen
            ['reuse' => true],                                      // gleiche Nonce: Replay
            [],                                                     // gleiche Event-ID, neue Nonce: Doppel (Timeout nach Erfolg)
            ['secret' => 'falsches-geheimnis-0123456'],            // falsche Signatur
            ['skew' => -301],                                       // abgelaufen
            ['key' => 'unbekannt'],                                 // unbekannter Schlüssel
            ['tamper' => true],                                     // veränderter Body
            ['event' => ['_contract' => '2.0.0', 'id' => '1f1e2d3c-4b5a-5968-8776-655443322110']],
            ['event' => ['type' => 'shop_account.deleted', 'id' => '2f1e2d3c-4b5a-5968-8776-655443322110']],
            ['event' => ['tenant_id' => 'ffffffff-2222-4333-8444-555555555555', 'id' => '3f1e2d3c-4b5a-5968-8776-655443322110']],
        ]);

        $this->assertTrue($out['registered']);
        $this->assertSame('boatingschool-connector', $out['manifest_key']);
        $this->assertSame([
            [200, ['result' => 'captured']], [409, ['code' => 'replay']], [200, ['result' => 'duplicate']],
            [401, ['code' => 'bad_signature']], [401, ['code' => 'stale_timestamp']], [401, ['code' => 'unknown_key']], [401, ['code' => 'bad_signature']],
            [422, ['code' => 'contract_incompatible']], [422, ['code' => 'event:unknown_type']], [422, ['code' => 'event:tenant_mismatch']],
        ], array_map(fn ($r) => [$r['status'], $r['body']], $out['responses']));

        $this->assertCount(1, $out['captured']);
        $captured = $out['captured'][0];
        $this->assertSame('boatingschool-connector', $captured['product']);
        $this->assertSame('xboatingschoolconnector.code_batch.delivered', $captured['event']['type']);
        $this->assertSame('0f1e2d3c-4b5a-5968-8776-655443322110', $captured['event']['discriminator']);
        $this->assertSame(['batch_id', 'tenant_id', 'shop_account_id', 'order_id', 'order_line_id', 'kind', 'quantity', 'course_key', 'product_key'], array_keys($captured['event']['data']));
        $this->assertStringNotContainsString('GEHEIM', json_encode($out));
        $this->assertStringNotContainsString('pia@', json_encode($out));

        // Diagnose: nur Zähler und Codes.
        $d = $out['diagnostics'];
        $this->assertSame([10, 1, 1], [$d['received'], $d['captured'], $d['duplicates']]);
        $this->assertSame(2, $d['refused']['bad_signature']);
        $this->assertSame('event:tenant_mismatch', $d['last_error']['code']);
    }

    public function test_without_a_valid_own_license_nothing_new_is_accepted(): void
    {
        foreach (['invalid' => 'license_invalid', 'library_missing' => 'license_library_missing'] as $license => $code) {
            $out = $this->receive([[]], ['license' => $license]);
            $this->assertSame([503, ['code' => $code]], [$out['responses'][0]['status'], $out['responses'][0]['body']]);
            $this->assertSame([], $out['captured']);
        }
    }

    public function test_the_connector_waits_for_the_operator_release_and_a_capture_refusal_is_retried(): void
    {
        $out = $this->receive([[]], ['stored' => []]);
        $this->assertSame([503, ['code' => 'connector_not_enabled']], [$out['responses'][0]['status'], $out['responses'][0]['body']]);
        $this->assertSame([], $out['captured']);

        // Workflow Captain lehnt ab (z. B. Schema): vorübergehend für die App, kein Doppel-Merker.
        $out = $this->receive([[], []], ['capture_result' => 'rejected']);
        $this->assertSame([503, 503], array_column($out['responses'], 'status'));
        $this->assertSame('capture_rejected', $out['responses'][0]['body']['code']);
    }

    public function test_key_rotation_and_separation_from_the_woocommerce_connector(): void
    {
        $constants = self::CONSTANTS + ['BSW_CONNECTOR_INBOUND_KEY_ID_NEXT' => 'app-workflow-2', 'BSW_CONNECTOR_INBOUND_SECRET_NEXT' => 'inbound-secret-next-0123456'];
        $out = $this->receive([['key' => 'app-workflow-2', 'secret' => 'inbound-secret-next-0123456'], ['event' => ['id' => '4f1e2d3c-4b5a-5968-8776-655443322110']]], ['constants' => $constants]);
        $this->assertSame([200, 200], array_column($out['responses'], 'status'));

        // Gleiches Geheimnis wie der WooCommerce-Connector: nie angenommen.
        $out = $this->receive([[]], ['constants' => self::CONSTANTS + ['BS_CONNECTOR_INBOUND_SECRET' => 'inbound-secret-0123456789']]);
        $this->assertSame([401, ['code' => 'unknown_key']], [$out['responses'][0]['status'], $out['responses'][0]['body']]);
    }

    public function test_the_notice_action_forwards_the_step_idempotency_key_and_maps_failures(): void
    {
        $envelope = ['source' => ['product' => 'boatingschool-connector'], 'subject' => ['type' => 'code_batch', 'id' => self::BATCH], 'data' => ['tenant_id' => self::TENANT]];
        $base = ['action' => 'xboatingschoolconnector.school_admin.notice', 'envelope' => $envelope, 'config' => ['template' => 'codes_delivered'], 'idempotency_key' => str_repeat('a1', 32)];

        $out = $this->harness('action', $base + ['responses' => [[200, ['result' => ['recipients' => 2, 'template' => 'codes_delivered', 'email' => 'x@example.test'], 'repeated' => false]]]]);
        $this->assertSame([[], 'manage_woocommerce', true], [$out['validate'], $out['capability'], $out['idempotent']]);
        $this->assertSame(['recipients' => 2, 'template' => 'codes_delivered', 'repeated' => false], $out['result']);
        $request = $out['requests'][0];
        $this->assertSame('https://lernen.example.test/api/integrations/v1/workflow/commands', $request['url']);
        $this->assertTrue($request['signature_valid']);
        $this->assertSame('workflow', $request['headers']['X-BSW-Key']);
        $this->assertSame([
            'op' => 'command', 'action' => 'school_admin.notice', 'idempotency_key' => str_repeat('a1', 32), 'scope' => 'tenant',
            'tenant_id' => self::TENANT, 'subject' => ['type' => 'code_batch', 'id' => self::BATCH], 'params' => ['template' => 'codes_delivered'],
        ], $request['body']);

        foreach ([
            [[[503, ['code' => 'ACTION_FAILED']]], 'transient', 'app_action_failed'],
            [[[429, ['code' => 'DAILY_LIMIT_REACHED']]], 'transient', 'app_daily_limit_reached'],
            [[null], 'transient', 'app_timeout'],
            [[[409, ['code' => 'COMMAND_IN_PROGRESS']]], 'transient', 'app_command_in_progress'],
            [[[403, ['code' => 'TENANT_MISMATCH']]], 'permanent', 'app_tenant_mismatch'],
            [[[403, ['code' => 'ACTION_FORBIDDEN']]], 'permanent', 'app_action_forbidden'],
        ] as [$responses, $kind, $reason]) {
            $this->assertSame($reason, $this->harness('action', $base + ['responses' => $responses])[$kind] ?? null, $reason);
        }

        $this->assertSame('event_not_from_boatingschool', $this->harness('action', ['envelope' => ['source' => ['product' => 'pass-captain']] + $envelope] + $base)['permanent']);
        $unlicensed = $this->harness('action', $base + ['license' => 'invalid']);
        $this->assertSame(['license_inactive', true, []], [$unlicensed['permanent'], $unlicensed['pause'], $unlicensed['requests']]);
        $this->assertSame(['template:invalid'], $this->harness('action', ['config' => ['template' => 'frei']] + $base + ['responses' => [[200, []]]])['validate']);
    }

    public function test_the_optional_invite_action_needs_all_references_in_its_configuration(): void
    {
        $config = ['tenant_id' => self::TENANT, 'group_id' => self::BATCH, 'user_id' => '22222222-2222-4333-8444-555555555555', 'acting_user_id' => '33333333-2222-4333-8444-555555555555'];
        $out = $this->harness('action', ['action' => 'xboatingschoolconnector.learning_group.invite', 'envelope' => [], 'config' => $config,
            'idempotency_key' => str_repeat('b2', 32), 'responses' => [[200, ['result' => ['invited' => 1, 'skipped' => 0], 'repeated' => false]]]]);
        $this->assertSame([], $out['validate']);
        $this->assertSame(['invited' => 1, 'skipped' => 0, 'repeated' => false], $out['result']);
        $this->assertSame(['type' => 'learning_group', 'id' => self::BATCH], $out['requests'][0]['body']['subject']);
        $this->assertSame(['user_id' => $config['user_id'], 'acting_user_id' => $config['acting_user_id']], $out['requests'][0]['body']['params']);
        $this->assertSame(['group_id:invalid'], $this->harness('action', ['action' => 'xboatingschoolconnector.learning_group.invite', 'envelope' => [],
            'config' => ['group_id' => 'x'] + $config, 'idempotency_key' => str_repeat('b2', 32), 'responses' => [[422, []]]])['validate']);
    }

    public function test_handshake_and_test_event_report_status_without_secrets(): void
    {
        $out = $this->harness('handshake', ['responses' => [[200, ['contract_version' => '1.0.0', 'compatible' => true, 'actions' => [['action' => 'school_admin.notice']]]]]]);
        $this->assertSame(['ok' => true, 'code' => 'ok', 'contract_version' => '1.0.0', 'actions' => ['school_admin.notice']], $out['handshake']);
        $this->assertSame(['op' => 'handshake', 'contract_version' => '1.0.0', 'connector_version' => '0.1.0'], $out['requests'][0]['body']);
        $this->assertStringEndsWith('/api/integrations/v1/workflow/handshake', $out['requests'][0]['url']);
        $this->assertTrue($out['diagnostics']['handshake']['ok']);

        $bad = $this->harness('handshake', ['responses' => [[409, ['code' => 'CONTRACT_INCOMPATIBLE', 'contract_version' => '2.0.0']]]]);
        $this->assertSame(['ok' => false, 'code' => 'contract_incompatible', 'contract_version' => '2.0.0'], array_slice($bad['handshake'], 0, 3));
        $this->assertSame('not_configured', $this->harness('handshake', ['constants' => ['BSW_CONNECTOR_APP_URL' => 'http://unsicher.example.test']])['handshake']['code']);

        $this->assertSame('connector_not_registered', $this->harness('test_event', ['stored' => self::RELEASED])['result']);
        $out = $this->harness('test_event', ['stored' => self::RELEASED, 'register' => true]);
        $this->assertSame('captured', $out['result']);
        $this->assertSame('xboatingschoolconnector.connector.tested', $out['captured'][0]['event']['type']);
        $this->assertSame(['app_url' => true, 'outbound_key' => true, 'inbound_key' => true, 'inbound_rotation' => false], $out['status']['settings']);
        $this->assertSame(['valid', true, 'accepted', true], [$out['status']['license'], $out['status']['workflow_captain'], $out['status']['registration'], $out['status']['enabled']]);
        $this->assertStringNotContainsString('secret-0123456789', json_encode($out));
        $this->assertSame('license_invalid', $this->harness('test_event', ['stored' => self::RELEASED, 'register' => true, 'license' => 'invalid'])['result']);
    }

    public function test_runtime_needs_workflow_captain_pro_with_the_custom_connectors_right(): void
    {
        // F-18a: ohne Workflow Captain Pro keine Anmeldung, keine Aktionen, keine Annahme von Ereignissen.
        $noPro = $this->harness('test_event', ['register' => true, 'stored' => self::RELEASED, 'no_pro' => true]);
        $this->assertSame('workflow_captain_pro_missing', $noPro['result']);
        $this->assertSame('workflow_captain_pro_missing', $noPro['status']['registration']);
        $this->assertSame('workflow_captain_pro_missing', $noPro['status']['runtime']);
        $this->assertStringStartsWith('Workflow Captain Pro fehlt oder das Recht fehlt', $noPro['runtime_message']);
        $this->assertSame([], $noPro['captured']);

        $received = $this->receive([[]], ['no_pro' => true]);
        $this->assertFalse($received['registered']);
        $this->assertSame([503, 'workflow_captain_pro_missing'], [$received['responses'][0]['status'], $received['responses'][0]['body']['code'] ?? $received['responses'][0]['code'] ?? null]);
        $this->assertSame([], $received['captured']);

        $actions = $this->harness('action', ['action' => 'xboatingschoolconnector.school_admin.notice', 'config' => [], 'envelope' => [], 'idempotency_key' => str_repeat('a', 64), 'no_pro' => true]);
        $this->assertSame([], $actions['actions']);

        // Pro aktiv, Lizenz ohne Recht: Workflow Captain lehnt ab (runtime_not_allowed).
        $denied = $this->harness('test_event', ['register' => true, 'stored' => self::RELEASED, 'runtime_denied' => true]);
        $this->assertSame('runtime_not_allowed', $denied['result']);
        $this->assertSame('runtime_not_allowed', $denied['status']['runtime']);
        $this->assertStringContainsString('workflow.custom_connectors', $denied['runtime_message']);

        $ok = $this->harness('test_event', ['register' => true, 'stored' => self::RELEASED]);
        $this->assertSame('ok', $ok['status']['runtime']);
        $this->assertNull($ok['runtime_message']);
    }
}
