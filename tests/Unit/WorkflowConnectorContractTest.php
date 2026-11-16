<?php

namespace Tests\Unit;

use App\Workflow\EventCatalog;
use App\Workflow\PayloadAllowlist;
use App\Workflow\WorkflowSignature;
use BSW_Manifest;
use BSW_Payload_Mapper;
use BSW_Signature;
use PHPUnit\Framework\TestCase;

/**
 * Vertrag zwischen App und WordPress-Plugin integrations/boatingschool-workflow-connector
 * (WF-CUST1-01, -04, -05, -08): gleiche Signatur, gleiche Ereignisse, gültiges
 * Manifest nach dem öffentlichen Connector-Vertrag von Workflow Captain,
 * Payload-Mapping nur über die Positivliste.
 *
 * Die Plugin-Klassen hier haben keine WordPress-Abhängigkeit und werden direkt geladen.
 */
class WorkflowConnectorContractTest extends TestCase
{
    /** Regeln aus WorkflowCaptain\Envelope\Validator bzw. Connector\Manifest (Stand Vertrag v1). */
    private const WFC_TYPE = '/^(voucher|pass|affiliate|license|form|review|course|workflow|wordpress|woocommerce|x[a-z0-9]{2,39})\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    private const WFC_FORBIDDEN_DATA_NAME = '/(^|_)(code|codes|token|tokens|secret|password|license_key|api_key|email|e_mail|mail|name|first_name|last_name|address|phone|iban|bic|message|note|path|url|ip)$/';

    private const WFC_PRODUCTS = ['voucher-captain', 'pass-captain', 'affiliate-captain', 'form-captain', 'review-captain', 'course-captain', 'workflow-captain', 'wp-license', 'wordpress', 'woocommerce'];

    public static function setUpBeforeClass(): void
    {
        if (! defined('BSW_CONNECTOR_TESTING')) {
            define('BSW_CONNECTOR_TESTING', true);
        }
        $dir = dirname(__DIR__, 2).'/integrations/boatingschool-workflow-connector/includes/';
        require_once $dir.'class-bsw-signature.php';
        require_once $dir.'class-bsw-manifest.php';
        require_once $dir.'class-bsw-payload-mapper.php';
    }

    public function test_plugin_and_app_sign_identically_in_both_directions(): void
    {
        $body = json_encode(['op' => 'event', 'event' => ['id' => 'x', 'data' => ['quantity' => 3]]]);
        $this->assertSame(WorkflowSignature::sign('geheim-geheim-geheim', 1700000000, 'nonce-nonce-nonce1', $body), BSW_Signature::sign('geheim-geheim-geheim', 1700000000, 'nonce-nonce-nonce1', $body));
        // Gleiche Form wie InboundGuard::sign() von Workflow Captain.
        $this->assertSame(hash_hmac('sha256', '1700000000.nonce-nonce-nonce1.'.$body, 'geheim-geheim-geheim'), BSW_Signature::sign('geheim-geheim-geheim', 1700000000, 'nonce-nonce-nonce1', $body));

        $h = WorkflowSignature::headers('app-workflow', 'out-secret-0123456789', $body);
        $this->assertTrue(BSW_Signature::verify('out-secret-0123456789', $h[WorkflowSignature::HEADER_TIMESTAMP], $h[WorkflowSignature::HEADER_NONCE], $body, $h[WorkflowSignature::HEADER_SIGNATURE]));
        $this->assertFalse(BSW_Signature::verify('out-secret-0123456789', $h[WorkflowSignature::HEADER_TIMESTAMP], $h[WorkflowSignature::HEADER_NONCE], $body.' ', $h[WorkflowSignature::HEADER_SIGNATURE]));
        $p = BSW_Signature::headers('workflow', 'in-secret-0123456789', $body);
        $this->assertTrue(WorkflowSignature::verify('in-secret-0123456789', $p[BSW_Signature::HEADER_TIMESTAMP], $p[BSW_Signature::HEADER_NONCE], $body, $p[BSW_Signature::HEADER_SIGNATURE]));
        $this->assertFalse(WorkflowSignature::verify('', $p[BSW_Signature::HEADER_TIMESTAMP], $p[BSW_Signature::HEADER_NONCE], $body, $p[BSW_Signature::HEADER_SIGNATURE]));
        $this->assertSame([WorkflowSignature::HEADER_KEY, WorkflowSignature::HEADER_TIMESTAMP, WorkflowSignature::HEADER_NONCE, WorkflowSignature::HEADER_SIGNATURE],
            [BSW_Signature::HEADER_KEY, BSW_Signature::HEADER_TIMESTAMP, BSW_Signature::HEADER_NONCE, BSW_Signature::HEADER_SIGNATURE]);
    }

    public function test_plugin_knows_exactly_the_events_of_the_app(): void
    {
        $app = [];
        foreach (EventCatalog::TYPES as $type => $spec) {
            $app[$type] = [$spec['version'], $spec['fields']];
        }
        $this->assertSame($app, BSW_Manifest::APP_EVENTS);
        $this->assertSame('1.0.0', BSW_Manifest::CONTRACT_VERSION);
    }

    public function test_the_manifest_follows_the_public_connector_contract_of_workflow_captain(): void
    {
        $m = BSW_Manifest::get('0.1.0');
        $this->assertSame([], array_diff(array_keys($m), ['key', 'name', 'version', 'contract_version', 'requires', 'events', 'actions', 'description']));
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{1,39}$/', $m['key']);
        $this->assertNotContains($m['key'], self::WFC_PRODUCTS);
        $prefix = 'x'.str_replace('-', '', $m['key']);
        $this->assertSame(BSW_Manifest::PREFIX, $prefix);
        $this->assertLessThanOrEqual(40, strlen($prefix));
        $this->assertLessThanOrEqual(80, mb_strlen($m['name']));
        $this->assertLessThanOrEqual(500, mb_strlen($m['description']));
        foreach (['version', 'contract_version'] as $f) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $m[$f]);
        }
        foreach ($m['requires'] as $what => $version) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]{0,39}$/', $what);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        }
        $this->assertArrayHasKey('workflow_captain', $m['requires']);

        $this->assertLessThanOrEqual(50, count($m['events']));
        foreach ($m['events'] as $type => $spec) {
            $this->assertMatchesRegularExpression(self::WFC_TYPE, $type);
            $this->assertStringStartsWith($prefix.'.', $type);
            $this->assertLessThanOrEqual(96, strlen($type));
            $this->assertSame(['fields', 'after_commit', 'actor'], array_keys($spec));
            $this->assertTrue($spec['after_commit'], 'Die App stellt erst nach dem Commit zu.');
            $this->assertLessThanOrEqual(191, mb_strlen($spec['actor']));
            $this->assertLessThanOrEqual(24, count($spec['fields']));
            foreach ($spec['fields'] as $field => $def) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $field);
                $this->assertDoesNotMatchRegularExpression(self::WFC_FORBIDDEN_DATA_NAME, $field, "$type.$field");
                $this->assertDoesNotMatchRegularExpression(PayloadAllowlist::FORBIDDEN_NAME, $field, "$type.$field");
                $this->assertSame(['type', 'class'], array_keys($def));
                $this->assertContains($def['type'], ['integer', 'number', 'boolean', 'string', 'currency', 'datetime']);
                $this->assertSame('normal', $def['class'], 'keine personenbezogenen Felder');
            }
        }
        $this->assertSame(array_values(array_unique($m['actions'])), $m['actions']);
        foreach ($m['actions'] as $action) {
            $this->assertMatchesRegularExpression('/^'.$prefix.'(\.[a-z][a-z0-9_]*){2}$/', $action);
        }
    }

    /** WF-CUST1-01: keine Aktion für Zustellung, Wiederholung, Sperre, Freigabe oder Kursrechte. */
    public function test_no_action_touches_what_the_woocommerce_connector_owns(): void
    {
        foreach (array_merge(array_keys(BSW_Manifest::ACTIONS), array_values(BSW_Manifest::ACTIONS)) as $action) {
            $this->assertDoesNotMatchRegularExpression('/(deliver|void|revoke|refund|retry|issue|approve|link|provision|grant|entitlement|coupon|order)/', $action);
        }
        $this->assertSame(['school_admin.notice', 'learning_group.invite'], array_values(BSW_Manifest::ACTIONS));
    }

    public function test_mapping_uses_only_the_allowlist_and_the_event_id_as_discriminator(): void
    {
        $id = '0f1e2d3c-4b5a-5968-8776-655443322110';
        $tenant = '11111111-2222-4333-8444-555555555555';
        $batch = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $event = [
            'id' => $id, 'type' => 'code_batch.delivered', 'version' => 1, 'occurred_at' => '2026-11-16T10:00:00Z', 'scope' => 'tenant',
            'tenant_id' => $tenant, 'subject' => ['type' => 'code_batch', 'id' => $batch], 'correlation_id' => 'order:x',
            'data' => [
                'batch_id' => $batch, 'tenant_id' => $tenant, 'shop_account_id' => null, 'order_id' => '7001', 'order_line_id' => '3',
                'kind' => 'course', 'quantity' => 2, 'course_key' => 'SBF-SEE', 'product_key' => null,
                // Nicht erlaubt -- darf nie durchgereicht werden:
                'code' => 'ABCD-EFGH', 'email' => 'pia@example.test', 'vat_id' => 'DE1',
            ],
        ];
        $mapped = BSW_Payload_Mapper::map($event);
        $this->assertIsArray($mapped);
        $this->assertSame('xboatingschoolconnector.code_batch.delivered', $mapped['type']);
        $this->assertSame(['type' => 'code_batch', 'id' => $batch, 'id_kind' => 'public'], $mapped['subject']);
        $this->assertSame($id, $mapped['discriminator']);
        $this->assertSame(['batch_id', 'tenant_id', 'shop_account_id', 'order_id', 'order_line_id', 'kind', 'quantity', 'course_key', 'product_key'], array_keys($mapped['data']));
        $this->assertStringNotContainsString('ABCD', json_encode($mapped));
        $this->assertStringNotContainsString('@', json_encode($mapped));

        foreach ([
            [['type' => 'shop_account.deleted'], 'event:unknown_type'],
            [['version' => 2], 'event:unsupported_version'],
            [['id' => 'nicht-uuid'], 'event:id_invalid'],
            [['subject' => ['type' => 'tenant', 'id' => $tenant]], 'event:subject_invalid'],
            [['data' => ['quantity' => '2'] + $event['data']], 'data:quantity:invalid'],
            [['data' => ['course_key' => 'SBF SEE!'] + $event['data']], 'data:course_key:invalid'],
            [['tenant_id' => 'ffffffff-2222-4333-8444-555555555555'], 'event:tenant_mismatch'],
        ] as [$change, $code]) {
            $this->assertSame($code, BSW_Payload_Mapper::map($change + $event));
        }
        $this->assertSame('event:invalid', BSW_Payload_Mapper::map('kein array'));

        $test = BSW_Payload_Mapper::test_event(1700000000);
        $this->assertSame('xboatingschoolconnector.connector.tested', $test['type']);
        $this->assertArrayHasKey($test['type'], BSW_Manifest::get('0.1.0')['events']);
    }
}
