<?php

namespace Tests\Feature\Workflow;

use App\Commerce\Signature;
use App\Workflow\WorkflowKeys;
use App\Workflow\WorkflowSignature;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * WF-CUST1-05, -09: eigener Vertrag und eigene Schlüssel -- HMAC über
 * "Zeitstempel.Nonce.Body", Zeitfenster, Replay-Schutz, Rotation,
 * Rate-Limit, Trennung von der Commerce-API und Contract-Handshake.
 */
class WorkflowInboundSecurityTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('workflow-key:workflow');
        RateLimiter::clear('workflow-key:workflow-next');
    }

    private function handshake(array $extra = []): array
    {
        return $extra + ['op' => 'handshake', 'contract_version' => '1.0.0', 'connector_version' => '0.1.0'];
    }

    public function test_the_handshake_reports_contract_events_and_enabled_actions(): void
    {
        $this->workflow('handshake', $this->handshake())->assertOk()
            ->assertJsonPath('contract_version', '1.0.0')->assertJsonPath('compatible', true)->assertJsonPath('key_id', 'workflow')
            ->assertJsonPath('scopes', ['tenant'])
            ->assertJsonPath('events.0.type', 'shop_account.registered')->assertJsonPath('events.0.version', 1)
            ->assertJsonPath('actions', [['action' => 'school_admin.notice', 'subjects' => ['shop_account', 'code_batch', 'tenant']]]);

        $this->workflow('handshake', $this->handshake(['contract_version' => '2.0.0']))->assertStatus(409)
            ->assertJsonPath('code', 'CONTRACT_INCOMPATIBLE')->assertJsonPath('contract_version', '1.0.0');
        $this->workflow('handshake', $this->handshake(['contract_version' => 'eins']))->assertStatus(422);
        $this->workflow('handshake', $this->handshake(['contract_version' => '1.4.0']))->assertOk();
    }

    public function test_wrong_unknown_expired_and_replayed_signatures_are_refused(): void
    {
        $this->postJson('/api/integrations/v1/workflow/handshake', $this->handshake())->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->workflow('handshake', $this->handshake(), 'workflow', 'falsches-geheimnis-123456')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->workflow('handshake', $this->handshake(), 'unbekannt')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->workflow('handshake', $this->handshake(), 'workflow', self::WORKFLOW_SECRET, time() - 301)->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_EXPIRED');
        $this->workflow('handshake', $this->handshake(), 'workflow', self::WORKFLOW_SECRET, time() + 301)->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_EXPIRED');

        // Veränderter Body bei gültigen Headern.
        $body = json_encode($this->handshake());
        $headers = WorkflowSignature::headers('workflow', self::WORKFLOW_SECRET, $body);
        $this->workflowRaw('handshake', str_replace('0.1.0', '0.1.1', $body), $headers)->assertStatus(401);

        // Replay: dieselbe Anfrage zweimal.
        $headers = WorkflowSignature::headers('workflow', self::WORKFLOW_SECRET, $body);
        $this->workflowRaw('handshake', $body, $headers)->assertOk();
        $this->workflowRaw('handshake', $body, $headers)->assertStatus(409)->assertJsonPath('code', 'SIGNATURE_REPLAYED');

        // Nonce in falscher Form.
        $ts = (string) time();
        $this->workflowRaw('handshake', $body, [WorkflowSignature::HEADER_KEY => 'workflow', WorkflowSignature::HEADER_TIMESTAMP => $ts,
            WorkflowSignature::HEADER_NONCE => 'kurz', WorkflowSignature::HEADER_SIGNATURE => WorkflowSignature::sign(self::WORKFLOW_SECRET, $ts, 'kurz', $body)])->assertStatus(401);
    }

    public function test_the_signed_body_must_name_its_endpoint_and_nothing_unsigned_is_accepted(): void
    {
        // Ein signierter Handshake-Body taugt nicht als Befehl (Pfad ist nicht signiert, "op" schon).
        $this->workflow('commands', $this->handshake())->assertStatus(400)->assertJsonPath('code', 'OP_MISMATCH');

        $body = json_encode($this->handshake());
        $this->workflowRaw('handshake', $body, WorkflowSignature::headers('workflow', self::WORKFLOW_SECRET, $body), '?tenant_id=x')
            ->assertStatus(400)->assertJsonPath('code', 'QUERY_NOT_ALLOWED');

        config(['workflow.max_body_bytes' => 100]);
        $big = json_encode($this->handshake(['padding' => str_repeat('x', 200)]));
        $this->workflowRaw('handshake', $big, WorkflowSignature::headers('workflow', self::WORKFLOW_SECRET, $big))->assertStatus(413);
    }

    public function test_key_rotation_accepts_the_next_key_and_refuses_the_retired_one(): void
    {
        $next = 'workflow-next-secret-abcdefgh';
        config(['workflow.keys' => [
            'workflow' => ['secret' => self::WORKFLOW_SECRET, 'scopes' => ['tenant']],
            'workflow-next' => ['secret' => $next, 'scopes' => ['tenant']],
        ]]);
        $this->workflow('handshake', $this->handshake())->assertOk()->assertJsonPath('key_id', 'workflow');
        $this->workflow('handshake', $this->handshake(), 'workflow-next', $next)->assertOk()->assertJsonPath('key_id', 'workflow-next');
        // Neues Geheimnis unter alter ID: abgelehnt.
        $this->workflow('handshake', $this->handshake(), 'workflow', $next)->assertStatus(401);

        // Alter Schlüssel ausgetragen.
        config(['workflow.keys' => ['workflow-next' => ['secret' => $next, 'scopes' => ['tenant']]]]);
        $this->workflow('handshake', $this->handshake())->assertStatus(401);
        $this->workflow('handshake', $this->handshake(), 'workflow-next', $next)->assertOk();
    }

    public function test_workflow_and_commerce_credentials_never_cross(): void
    {
        // Workflow-signierter Aufruf an die Commerce-API.
        $body = json_encode(['shop_customer_id' => '1', 'email' => 'x@example.test']);
        $this->call('POST', '/api/commerce/v1/accounts', [], [], [], $this->transformHeadersToServerVars(
            WorkflowSignature::headers('workflow', self::WORKFLOW_SECRET, $body) + ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        ), $body)->assertStatus(401);
        // Commerce-signierter Aufruf an den Workflow-Eingang.
        $body = json_encode($this->handshake());
        $uri = '/api/integrations/v1/workflow/handshake';
        $this->workflowRaw('handshake', $body, Signature::headers('shop', self::COMMERCE_SECRET, 'POST', $uri, $body))->assertStatus(401);
        // Commerce-Geheimnis als Workflow-Schlüssel, oder Commerce-Schlüssel-ID: nie angenommen.
        config(['workflow.keys' => ['shop' => ['secret' => 'eigenes-geheimnis-123456', 'scopes' => ['tenant']], 'wf' => ['secret' => self::COMMERCE_SECRET, 'scopes' => ['tenant']]]]);
        $this->workflow('handshake', $this->handshake(), 'shop', 'eigenes-geheimnis-123456')->assertStatus(401);
        $this->workflow('handshake', $this->handshake(), 'wf', self::COMMERCE_SECRET)->assertStatus(401);
        $this->assertEqualsCanonicalizing(['key_id_reused_from_commerce', 'secret_reused_from_commerce'], WorkflowKeys::problems());
        // Zu kurzes Geheimnis: nicht angenommen.
        config(['workflow.keys' => ['wf' => ['secret' => 'kurz', 'scopes' => ['tenant']]]]);
        $this->assertSame([], WorkflowKeys::all());
        // Ohne Schlüssel ist der Eingang zu.
        config(['workflow.keys' => []]);
        $this->workflow('handshake', $this->handshake())->assertStatus(401);
    }

    public function test_each_key_has_its_own_rate_limit(): void
    {
        config(['workflow.rate_limit_per_minute' => 2]);
        $this->workflow('handshake', $this->handshake())->assertOk();
        $this->workflow('handshake', $this->handshake())->assertOk();
        $this->workflow('handshake', $this->handshake())->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        // Der Commerce-Topf ist davon unberührt.
        $this->commerce('GET', '/catalog')->assertOk();
    }

    public function test_refusals_echo_nothing_of_the_request(): void
    {
        $secret = 'marker-'.Str::random(12);
        $response = $this->workflow('commands', $this->command(['action' => $secret, 'tenant_id' => $secret]));
        $this->assertStringNotContainsString($secret, $response->getContent());
    }
}
