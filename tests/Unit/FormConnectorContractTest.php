<?php

namespace Tests\Unit;

use App\Integrations\FormCaptain\LeadSchema;
use App\Integrations\FormCaptain\LeadSignature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WordPress-Connector integrations/boatingschool-form-connector (BS-10 bis BS-13) gegen den Connector-Vertrag v1 von
 * Form Captain (docs/CONNECTORS.md dort, Stil der ConnectorContractAssertions des Referenz-Connectors) und gegen den
 * Lead-Vertrag form-captain-lead/1 dieser Plattform. Die Plugin-Klassen laufen mit einem minimalen WordPress- und
 * Form-Captain-Ersatz in einem eigenen Prozess (form-connector-wp-harness.php) -- kein Test gegen echtes WordPress.
 */
class FormConnectorContractTest extends TestCase
{
    private const CONFIG = [
        'BOATINGSCHOOL_FORM_ENDPOINT' => 'https://app.boatingschool.example',
        'BOATINGSCHOOL_FORM_KEY_ID' => 'fc-lead-2026-11',
        'BOATINGSCHOOL_FORM_SECRET' => 'lead-secret-aaaaaaaaaaaaaaaa',
    ];

    private const LEAD_ID = 'L-01JD0000000000000000000000';

    private function harness(string $scenario, array $options = []): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/form-connector-wp-harness.php', $scenario, json_encode($options + ['form_captain' => true])]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function job(array $overrides = []): array
    {
        return array_replace_recursive([
            'form_id' => 7,
            'entry_id' => 123,
            'idempotency_key' => 'form-captain:1:123:boatingschool_form.send_lead:a_0123456789',
            'settings' => ['purpose' => 'consultation', 'consent_text_revision' => '3', 'consent_text_hash' => str_repeat('b', 64), 'page_url' => 'https://www.boatingschool.example/beratung'],
            'values' => ['email' => 'kim@example.test', 'consent' => '1', 'name' => 'Kim Meer', 'organisation' => 'Segelschule Meer', 'school_size' => '3-10', 'message' => "Zeile 1\nZeile 2"],
            'context' => [],
            'test_mode' => false,
            'attempt' => 1,
            'unknown_future_key' => 'wird ignoriert',
        ], $overrides);
    }

    private function send(array $jobs, array $responses, array $extra = []): array
    {
        return $this->harness('send', $extra + [
            'constants' => self::CONFIG,
            'entries' => ['123' => ['entry_id' => 123, 'form_id' => 7, 'created_gmt' => '2026-11-16T10:00:05Z']],
            'responses' => $responses,
            'jobs' => $jobs,
        ]);
    }

    /** assertActionResultShape() des Testkits von Form Captain. */
    private function assertActionResultShape(array $result): void
    {
        $this->assertContains($result['status'] ?? null, ['success', 'retry', 'fail']);
        $this->assertMatchesRegularExpression('/^[a-z0-9_.\-]{1,60}$/', $result['code']);
        $this->assertLessThanOrEqual(255, strlen($result['message']));
        if (isset($result['remote_id'])) {
            $this->assertLessThanOrEqual(191, strlen($result['remote_id']));
        }
        $this->assertStringNotContainsString('kim@example.test', json_encode($result), 'keine Personendaten in Meldungen');
    }

    public function test_connector_shape_and_action_definition_follow_contract_v1(): void
    {
        $out = $this->harness('shape');

        $this->assertArrayHasKey('FormCaptain\\Integrations\\ConnectorInterface', $out['interfaces']);
        $this->assertSame('boatingschool_form', $out['key']);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $out['key']);
        $this->assertSame('1.0', $out['contract']);
        $this->assertSame(['boatingschool_form.send_lead'], $out['capabilities']);
        $this->assertFalse($out['requirements']['ok'], 'ohne Adresse und Geheimnis nicht bereit (standardmäßig aus)');

        $action = $out['actions']['boatingschool_form.send_lead'];
        $this->assertSame('^1.0', $action['contract']);
        $this->assertSame('boatingschool_form', $action['connector']);
        $this->assertSame(['entry_created'], $action['events']);
        $keys = array_column($action['mapping'], 'key');
        $this->assertSame(['email', 'consent', 'name', 'phone', 'organisation', 'role', 'school_size', 'message', 'product'], $keys, 'nur die Felder des Schemas');
        $this->assertSame(['boatingschool_products'], $action['mapping'][8]['types']);
        $this->assertSame(['support', 'demo', 'consulting', 'school_access', 'pricing'], array_keys($action['settings'][0]['options']));
        foreach ($action['mapping'] as $field) {
            $this->assertArrayNotHasKey('allow_sensitive', $field, 'nie sensible Felder');
            $this->assertEmpty(array_intersect($field['types'], ['file', 'password', 'payment', 'signature', 'html', 'hidden']));
        }
        $this->assertTrue($action['mapping'][0]['required'] && $action['mapping'][1]['required'], 'E-Mail und Einwilligung Pflicht');
        $this->assertSame(['purpose', 'consent_text_revision', 'consent_text_hash', 'customer_reference', 'page_url'], array_column($action['settings'], 'key'));
    }

    public function test_lead_is_sent_signed_with_only_mapped_fields_and_matches_the_platform_schema(): void
    {
        $out = $this->send([$this->job(['values' => ['iban' => 'DE89370400440532013000', 'ip' => '203.0.113.9']])], [['status' => 201, 'body' => ['code' => 'created', 'lead_id' => self::LEAD_ID, 'status' => 'new']]]);

        $result = $out['results'][0];
        $this->assertActionResultShape($result);
        $this->assertSame(['status' => 'success', 'code' => 'created', 'message' => '', 'remote_id' => self::LEAD_ID], $result);

        $request = $out['http'][0];
        $this->assertSame('https://app.boatingschool.example/api/integrations/v1/form-captain/leads', $request['url']);
        $this->assertSame('POST', $request['method']);
        $this->assertSame(10, $request['timeout']);
        $this->assertSame('form-captain:1:123:boatingschool_form.send_lead:a_0123456789', $request['headers']['Idempotency-Key']);

        // HMAC-Kompatibilität: die Plattform berechnet dieselbe Signatur.
        $h = $request['headers'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $h['X-FC-Nonce']);
        $this->assertSame(LeadSignature::sign(self::CONFIG['BOATINGSCHOOL_FORM_SECRET'], 'POST', '/api/integrations/v1/form-captain/leads', $h['X-FC-Timestamp'], $h['X-FC-Nonce'], $request['body']), $h['X-FC-Signature']);

        $body = json_decode($request['body'], true);
        $this->assertSame([], LeadSchema::validate($body), 'Body entspricht form-captain-lead/1');
        $this->assertSame(['email' => 'kim@example.test', 'name' => 'Kim Meer', 'organisation' => 'Segelschule Meer'], $body['contact']);
        $this->assertSame(['site_url' => 'https://www.boatingschool.example', 'form_id' => 7, 'entry_id' => 123, 'plugin_version' => '1.1.0', 'page_url' => 'https://www.boatingschool.example/beratung'], $body['source']);
        $this->assertSame(['given_at' => '2026-11-16T10:00:05Z', 'text_revision' => 3, 'text_hash' => str_repeat('b', 64)], $body['consent']);
        $this->assertSame('2026-11-16T10:00:05Z', $body['submitted_at']);
        $this->assertStringNotContainsString('DE89370400440532013000', $request['body']);
        $this->assertStringNotContainsString('203.0.113.9', $request['body']);

        // Merkbuch für Löschungen: nur IDs, autoload = no.
        $this->assertSame(self::LEAD_ID, $out['options']['boatingschool_form_ledger']['123']['lead_id']);
        $this->assertFalse($out['autoload']['boatingschool_form_ledger']);
    }

    public function test_retry_after_timeout_sends_the_identical_body_and_keeps_the_remote_id(): void
    {
        // assertIdempotentCallback(): erster Versuch ohne Antwort (Timeout nach dem Schreiben), zweiter liefert die gleiche lead_id.
        $out = $this->send([$this->job(), $this->job(['attempt' => 2])], [
            ['error' => 'cURL error 28: Operation timed out after 10001 milliseconds'],
            ['status' => 200, 'body' => ['code' => 'created', 'lead_id' => self::LEAD_ID, 'status' => 'new']],
        ]);

        $this->assertSame(['status' => 'retry', 'code' => 'timeout', 'message' => 'Plattform nicht erreichbar oder nicht eingerichtet.'], $out['results'][0]);
        $this->assertSame('success', $out['results'][1]['status']);
        $this->assertSame('already_created', $out['results'][1]['code']);
        $this->assertSame(self::LEAD_ID, $out['results'][1]['remote_id']);
        $this->assertSame($out['http'][0]['body'], $out['http'][1]['body'], 'deterministischer Body');
        $this->assertSame($out['http'][0]['headers']['Idempotency-Key'], $out['http'][1]['headers']['Idempotency-Key']);
        $this->assertNotSame($out['http'][0]['headers']['X-FC-Nonce'], $out['http'][1]['headers']['X-FC-Nonce'], 'neue Nonce je Versuch');
    }

    public function test_platform_answers_map_to_retry_or_final_results(): void
    {
        $r = fn (int $status, string $code = '', array $body = [], string $error = '') => ['status' => $status, 'code' => $code, 'body' => $body + ($code !== '' ? ['code' => $code] : []), 'retry_after' => 0, 'error' => $error];
        $cases = [
            'created' => [['response' => $r(201, 'created', ['lead_id' => self::LEAD_ID])], 'success', 'created'],
            'replay' => [['response' => $r(200, 'created', ['lead_id' => self::LEAD_ID])], 'success', 'already_created'],
            'merged' => [['response' => $r(202, 'accepted_merged', ['lead_id' => self::LEAD_ID])], 'success', 'accepted_merged'],
            'no_lead_id' => [['response' => $r(201, 'created')], 'retry', 'invalid_response'],
            'bad_request' => [['response' => $r(400, 'bad_request')], 'fail', 'bad_request'],
            'expired_first' => [['response' => $r(401, 'signature_expired'), 'attempt' => 1], 'retry', 'signature_expired'],
            'expired_again' => [['response' => $r(401, 'signature_expired'), 'attempt' => 2], 'fail', 'signature_expired'],
            'invalid' => [['response' => $r(401, 'signature_invalid')], 'fail', 'signature_invalid'],
            'unknown_key' => [['response' => $r(401, 'key_unknown')], 'fail', 'key_unknown'],
            'revoked' => [['response' => $r(403, 'key_revoked')], 'fail', 'key_revoked'],
            'replay_nonce' => [['response' => $r(409, 'replay_detected')], 'retry', 'replay_detected'],
            'conflict' => [['response' => $r(409, 'idempotency_conflict')], 'fail', 'idempotency_conflict'],
            'schema' => [['response' => $r(422, 'schema_invalid')], 'fail', 'schema_invalid'],
            'rate' => [['response' => $r(429, 'rate_limited')], 'retry', 'rate_limited'],
            'server' => [['response' => $r(503)], 'retry', 'remote_unavailable'],
            'network' => [['response' => $r(0, '', [], 'remote_unavailable')], 'retry', 'remote_unavailable'],
        ];
        $out = $this->harness('map', ['cases' => array_column($cases, 0)]);

        foreach (array_keys($cases) as $i => $name) {
            $this->assertActionResultShape($out['results'][$i]);
            $this->assertSame([$cases[$name][1], $cases[$name][2]], [$out['results'][$i]['status'], $out['results'][$i]['code']], $name);
        }
    }

    public function test_test_mode_only_pings_and_creates_nothing(): void
    {
        $out = $this->send([$this->job(['test_mode' => true, 'entry_id' => 0])], [['status' => 200, 'body' => ['ok' => true, 'schema' => 'form-captain-lead/1', 'key_id' => 'fc-lead-2026-11']]]);

        $this->assertSame('success', $out['results'][0]['status']);
        $this->assertSame('test_ok', $out['results'][0]['code']);
        $this->assertCount(1, $out['http']);
        $this->assertStringEndsWith('/api/integrations/v1/form-captain/ping', $out['http'][0]['url']);
        $this->assertSame('{}', $out['http'][0]['body']);
        $this->assertArrayNotHasKey('boatingschool_form_ledger', $out['options']);
    }

    public function test_source_url_allowlist_consent_and_configuration_are_checked_before_sending(): void
    {
        $out = $this->send([
            $this->job(['settings' => ['page_url' => 'https://fremde-seite.example/formular']]),
            $this->job(['values' => ['consent' => '']]),
            $this->job(['settings' => ['consent_text_revision' => '0']]),
            $this->job(['idempotency_key' => '']),
        ], []);
        $this->assertSame([['fail', 'source_url_not_allowed'], ['fail', 'consent_missing'], ['fail', 'consent_revision_missing'], ['fail', 'missing_idempotency_key']],
            array_map(fn ($r) => [$r['status'], $r['code']], $out['results']));
        $this->assertSame([], $out['http'], 'nichts gesendet');

        // Erlaubter weiterer Host.
        $out = $this->send([$this->job(['settings' => ['page_url' => 'https://kampagne.boatingschool.example/demo']])], [['status' => 201, 'body' => ['lead_id' => self::LEAD_ID]]],
            ['constants' => self::CONFIG + ['BOATINGSCHOOL_FORM_ALLOWED_HOSTS' => 'www.boatingschool.example, kampagne.boatingschool.example']]);
        $this->assertSame('success', $out['results'][0]['status']);

        // Ohne Konfiguration: nichts senden, später erneut (requirements_not_met).
        $out = $this->harness('send', ['jobs' => [$this->job()], 'entries' => ['123' => ['created_gmt' => '2026-11-16T10:00:05Z']]]);
        $this->assertSame(['retry', 'not_configured'], [$out['results'][0]['status'], $out['results'][0]['code']]);
        $this->assertSame([], $out['http']);

        // Nur HTTPS (außer localhost).
        $out = $this->harness('send', ['jobs' => [$this->job()], 'constants' => ['BOATINGSCHOOL_FORM_ENDPOINT' => 'http://app.boatingschool.example'] + self::CONFIG]);
        $this->assertSame('not_configured', $out['results'][0]['code']);
    }

    private const WOO = [
        'active' => true,
        'user' => 17,
        'customers' => ['17' => ['first' => 'Kim', 'last' => 'Meer', 'email' => 'kim.konto@example.test'], '18' => ['first' => 'Eve', 'last' => 'Fremd', 'email' => 'eve@example.test']],
        'orders' => [
            ['customer' => 17, 'status' => 'completed', 'items' => [['product_id' => 42, 'name' => 'SBF-See Onlinekurs'], ['product_id' => 43, 'name' => 'Funkkurs SRC']]],
            ['customer' => 17, 'status' => 'pending', 'items' => [['product_id' => 44, 'name' => 'Unbezahlt']]],
            ['customer' => 18, 'status' => 'completed', 'items' => [['product_id' => 45, 'name' => 'Fremdes Produkt']]],
        ],
        'products' => ['42' => ['name' => 'SBF-See Onlinekurs', 'sku' => 'SBF-SEE'], '45' => ['name' => 'Fremdes Produkt', 'sku' => 'X']],
    ];

    public function test_my_products_field_offers_and_accepts_only_the_customers_own_paid_purchases(): void
    {
        $products = ['id' => 'f1', 'type' => 'boatingschool_products', 'required' => true];
        $out = $this->harness('fields', ['woo' => self::WOO,
            'render' => [$products, ['id' => 'f2', 'type' => 'boatingschool_customer']],
            'validate' => [[$products, '42'], [$products, '45'], [$products, '44'], [$products, '42 OR 1=1'], [$products, ''], [['type' => 'boatingschool_customer'], 'egal']],
            'display' => [[$products, '{"product_id":42,"customer_id":17}'], [$products, '{"text":"Funkkurs"}']],
            'normalize' => [['type' => 'boatingschool_products', 'x' => ['guest_mode' => 'text']], ['type' => 'boatingschool_products', 'x' => ['guest_mode' => 'boese']], ['type' => 'text']],
        ]);

        $this->assertSame(['has_value' => true], array_intersect_key($out['types']['boatingschool_products'], ['has_value' => 1]));
        $this->assertFalse($out['types']['boatingschool_customer']['has_value'], 'Kundeninfo speichert keinen Wert');
        // Nur WooCommerce-CRUD, nur Bestellungen des aktuellen Benutzers, nur bezahlte Status.
        $this->assertSame(17, $out['order_queries'][0]['customer_id']);
        $this->assertSame(['processing', 'completed'], $out['order_queries'][0]['status']);
        $this->assertCount(1, $out['order_queries'], 'Käufe je Anfrage einmal gelesen');

        $select = $out['render'][0];
        $this->assertStringContainsString('<option value="42">SBF-See Onlinekurs</option>', $select);
        $this->assertStringContainsString('<option value="43">Funkkurs SRC</option>', $select);
        $this->assertStringNotContainsString('Unbezahlt', $select);
        $this->assertStringNotContainsString('Fremdes Produkt', $select);
        $this->assertStringContainsString(' required', $select);
        // Kundeninfo: nur Anzeige.
        foreach (['Kim Meer', 'kim.konto@example.test', '<dd>17</dd>'] as $text) {
            $this->assertStringContainsString($text, $out['render'][1]);
        }
        $this->assertStringNotContainsString('<input', $out['render'][1]);

        $this->assertSame([
            ['value' => '{"product_id":42,"customer_id":17}'],
            ['code' => 'product_not_purchased'],
            ['code' => 'product_not_purchased'],
            ['code' => 'product_not_purchased'],
            ['code' => 'required'],
            ['value' => null],
        ], $out['validate']);
        $this->assertSame(['SBF-See Onlinekurs (SKU SBF-SEE) · Kundennummer 17', 'Funkkurs (Freitext, ohne Anmeldung)'], $out['display']);
        $this->assertSame([['settings' => ['guest_mode' => 'text']], ['settings' => ['guest_mode' => 'notice']], null], $out['normalize']);
        $this->assertSame(['boatingschool_products'], $out['panels'][0]['types']);
        $this->assertNull($out['other_type_untouched'], 'fremde Feldtypen bleiben unberührt');
    }

    public function test_my_products_field_without_login_shows_a_notice_or_a_free_text_field(): void
    {
        $notice = ['id' => 'f1', 'type' => 'boatingschool_products', 'required' => true];
        $text = ['id' => 'f1', 'type' => 'boatingschool_products', 'required' => true, 'x' => ['guest_mode' => 'text']];
        $out = $this->harness('fields', ['woo' => ['user' => 0] + self::WOO,
            'render' => [$notice, $text, ['type' => 'boatingschool_customer']],
            'validate' => [[$notice, '42'], [$text, '<b>Funkkurs</b>'], [$text, ''], [['type' => 'boatingschool_products'], '42']],
        ]);

        $this->assertStringContainsString('Bitte melden Sie sich', $out['render'][0]);
        $this->assertStringNotContainsString('<select', $out['render'][0]);
        $this->assertStringContainsString('<input type="text"', $out['render'][1]);
        $this->assertStringContainsString('Melden Sie sich an', $out['render'][2]);
        $this->assertSame([['code' => 'login_required'], ['value' => '{"text":"Funkkurs"}'], ['code' => 'required'], ['value' => null]], $out['validate']);
        $this->assertSame([], $out['order_queries'], 'ohne Anmeldung keine Bestellabfrage');

        // Vor Contract 1.1.0 keine eigenen Feldtypen.
        $old = $this->harness('fields', ['contract' => '1.0.0', 'woo' => self::WOO]);
        $this->assertSame([], $old['types']);
    }

    public function test_support_lead_sends_product_and_customer_reference_only_for_purpose_support(): void
    {
        $support = $this->job(['settings' => ['purpose' => 'support', 'customer_reference' => '1'], 'values' => ['product' => '{"product_id":42,"customer_id":17}']]);
        $noReference = $this->job(['settings' => ['purpose' => 'support'], 'values' => ['product' => '{"product_id":42,"customer_id":17}']]);
        $demo = $this->job(['settings' => ['purpose' => 'demo', 'customer_reference' => '1'], 'values' => ['product' => '{"product_id":42,"customer_id":17}']]);
        $guest = $this->job(['settings' => ['purpose' => 'support', 'customer_reference' => '1'], 'values' => ['product' => '{"text":"Funkkurs"}']]);
        $ok = ['status' => 201, 'body' => ['lead_id' => self::LEAD_ID]];
        $out = $this->send([$support, $noReference, $demo, $guest], [$ok, $ok, $ok, $ok], ['woo' => ['user' => 0] + self::WOO]);

        $bodies = array_map(fn ($r) => json_decode($r['body'], true), $out['http']);
        $this->assertSame(['id' => 42, 'name' => 'SBF-See Onlinekurs', 'sku' => 'SBF-SEE'], $bodies[0]['product']);
        $this->assertSame(['id' => 17, 'email' => 'kim.konto@example.test'], $bodies[0]['customer'], 'E-Mail aus dem Kundenkonto, im Worker gelesen');
        $this->assertSame('support', $bodies[0]['purpose']);
        $this->assertArrayNotHasKey('customer', $bodies[1], 'Kundenreferenz nur mit Einstellung');
        $this->assertArrayHasKey('product', $bodies[1]);
        $this->assertArrayNotHasKey('product', $bodies[2], 'Produkt nur beim Zweck support');
        $this->assertArrayNotHasKey('customer', $bodies[2]);
        $this->assertSame(['name' => 'Funkkurs'], $bodies[3]['product']);
        $this->assertArrayNotHasKey('customer', $bodies[3]);
        foreach ($bodies as $body) {
            $this->assertSame([], LeadSchema::validate($body), 'Body entspricht form-captain-lead/1 (Vertrag 1.1)');
        }
    }

    public function test_entry_deletion_and_consent_withdrawal_delete_the_remote_lead(): void
    {
        $ledger = ['boatingschool_form_ledger' => [
            '123' => ['lead_id' => self::LEAD_ID, 'form_id' => 7, 'at' => time()],
            '124' => ['lead_id' => 'L-01JD0000000000000000000001', 'form_id' => 7, 'at' => time()],
        ]];
        $out = $this->harness('privacy', ['constants' => self::CONFIG, 'stored' => $ledger, 'responses' => [['status' => 204], ['status' => 503]],
            'events' => [['form_captain_entry_deleted_v1', [123, 7, 'manual']], ['form_captain_consent_withdrawn_v1', [124, 7, 'admin']], ['form_captain_entry_deleted_v1', [999, 7, 'manual']]]]);

        $this->assertCount(2, $out['http'], 'unbekannter Entry: kein Aufruf');
        $this->assertSame('DELETE', $out['http'][0]['method']);
        $this->assertStringEndsWith('/leads/'.self::LEAD_ID, $out['http'][0]['url']);
        $h = $out['http'][0]['headers'];
        $this->assertSame(LeadSignature::sign(self::CONFIG['BOATINGSCHOOL_FORM_SECRET'], 'DELETE', '/api/integrations/v1/form-captain/leads/'.self::LEAD_ID, $h['X-FC-Timestamp'], $h['X-FC-Nonce'], ''), $h['X-FC-Signature']);
        $this->assertSame([], $out['options']['boatingschool_form_ledger']);
        // Plattform nicht erreichbar: Löschung bleibt offen und wird stündlich wiederholt.
        $this->assertSame(['L-01JD0000000000000000000001' => 1], $out['options']['boatingschool_form_pending_deletions']);
        $this->assertArrayHasKey('boatingschool_form_retry_deletions', $out['cron']);

        // Abgeschaltet: nur lokal vergessen.
        $out = $this->harness('privacy', ['constants' => self::CONFIG + ['BOATINGSCHOOL_FORM_REMOTE_DELETE' => false], 'stored' => $ledger,
            'events' => [['form_captain_entry_deleted_v1', [123, 7, 'manual']]]]);
        $this->assertSame([], $out['http']);
        $this->assertArrayNotHasKey('123', $out['options']['boatingschool_form_ledger']);
    }

    public function test_without_lib_the_connector_stays_inactive_with_an_admin_notice(): void
    {
        $out = $this->harness('boot', ['with_lib' => false, 'constants' => self::CONFIG]);

        $this->assertFalse($out['lib']);
        $this->assertSame([], $out['connectors'], 'fail-closed: nicht bei Form Captain angemeldet');
        $this->assertStringContainsString('Ordner „lib“', $out['notices']);
    }

    public function test_with_lib_but_without_license_the_connector_stays_inactive(): void
    {
        if (! is_file(dirname(__DIR__, 2).'/integrations/boatingschool-form-connector/lib/kernel/load.php')) {
            $this->markTestSkipped('lib/ fehlt (wird nur beim Bau aus captain-pro erzeugt, bin/build-libs.php).');
        }
        $out = $this->harness('boot', ['with_lib' => true, 'constants' => self::CONFIG]);

        $this->assertTrue($out['lib']);
        $this->assertSame([], $out['connectors'], 'ohne Lizenz nicht bei Form Captain angemeldet');
    }

    public function test_plugin_metadata_catalog_and_public_repository_rules(): void
    {
        $dir = dirname(__DIR__, 2).'/integrations/boatingschool-form-connector';
        $main = (string) file_get_contents($dir.'/boatingschool-form-connector.php');
        $config = (string) file_get_contents($dir.'/config.php');
        $catalog = json_decode((string) file_get_contents($dir.'/wplc-catalog.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression('/^ \* Text Domain:\s+boatingschool-form-connector$/m', $main);
        $this->assertMatchesRegularExpression('/^ \* Requires Plugins:\s+form-captain$/m', $main);
        preg_match('/^ \* Version:\s+(\S+)$/m', $main, $version);
        $this->assertStringContainsString("define('BOATINGSCHOOL_FORM_VERSION', '{$version[1]}')", $main);
        $this->assertStringContainsString("'BOATINGSCHOOL_FORM_CONNECTOR_VERSION', '{$version[1]}'", $config);
        preg_match('/^ \* Update URI:\s+(\S+)$/m', $main, $uri);
        $this->assertStringContainsString("'update_uri' => '{$uri[1]}'", $config);

        $this->assertSame('boatingschool-form-connector', $catalog['product_key']);
        $this->assertSame('boatingschool_form', $catalog['prefix']);
        $this->assertSame(['boatingschool_form.leads'], array_column($catalog['entitlements'], 'key'));
        $this->assertStringContainsString("'entitlement_prefix' => 'boatingschool_form.'", $config);
        $this->assertStringContainsString("public const KEY = 'boatingschool_form.leads'", (string) file_get_contents($dir.'/src/Features/LeadDelivery.php'));
        $this->assertNotContains($catalog['status'], ['available'], 'unverkauftes Projektprodukt');

        // BS-03: Kernel und Lizenzclient nie im öffentlichen Repository.
        $this->assertStringContainsString('/lib/', (string) file_get_contents($dir.'/.gitignore'));
        $tracked = new Process(['git', 'ls-files', 'integrations/boatingschool-form-connector'], dirname(__DIR__, 2));
        $tracked->run();
        if ($tracked->isSuccessful() && trim($tracked->getOutput()) !== '') {
            $this->assertDoesNotMatchRegularExpression('#/lib/#', $tracked->getOutput());
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('namespace Captain\\AddonKernel', $code);
            $this->assertStringNotContainsString('namespace WPLC\\Client', $code);
        }
    }
}
