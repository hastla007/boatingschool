<?php

namespace Tests\Feature\Integrations;

use App\Models\FormCaptainLead;
use App\Models\FormCaptainLeadRequest;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * WordPress-Connector → Plattform ohne Netz (BS-13, lokaler Teil): Der echte Connector-Code baut und signiert die
 * Anfrage im WordPress-Ersatz (tests/Unit/form-connector-wp-harness.php), die Plattform nimmt genau diese Anfrage
 * an. Eine Wiederholung desselben Jobs (Timeout nach erfolgreichem Schreiben, manuelle Wiederholung in Form Captain)
 * liefert dieselbe lead_id. Der Ende-zu-Ende-Lauf auf Staging (echtes WordPress, echtes Netz) bleibt offen.
 */
class FormConnectorEndToEndTest extends TestCase
{
    use InteractsWithTenants;

    private const SECRET = 'lead-secret-e2e-aaaaaaaaaaaa';

    private int $entryId;

    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entryId = random_int(1_000_000, 9_999_999);
        $this->email = 'e2e-'.Str::lower(Str::random(6)).'@example.test';
        config(['form_captain.keys' => ['fc-lead-e2e' => self::SECRET], 'form_captain.allowed_site_urls' => ['https://www.boatingschool.example']]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $ids = FormCaptainLead::where('site_url', 'https://www.boatingschool.example')->where('entry_id', $this->entryId)->pluck('public_id');
            FormCaptainLeadRequest::whereIn('lead_public_id', $ids)->delete();
            FormCaptainLead::whereIn('public_id', $ids)->delete();
        });
        parent::tearDown();
    }

    /** @return array{url:string,method:string,headers:array<string,string>,body:string} vom Connector gebaute Anfrage */
    private function connectorRequest(array $settings = [], array $values = [], array $woo = []): array
    {
        $options = [
            'woo' => $woo,
            'form_captain' => true,
            'constants' => ['BOATINGSCHOOL_FORM_ENDPOINT' => 'http://localhost', 'BOATINGSCHOOL_FORM_KEY_ID' => 'fc-lead-e2e', 'BOATINGSCHOOL_FORM_SECRET' => self::SECRET],
            'entries' => [(string) $this->entryId => ['created_gmt' => '2026-11-16T10:00:05Z']],
            'responses' => [['error' => 'Operation timed out']],
            'jobs' => [[
                'form_id' => 7,
                'entry_id' => $this->entryId,
                'idempotency_key' => 'form-captain:1:'.$this->entryId.':boatingschool_form.send_lead:a_e2e0000001',
                'settings' => $settings + ['purpose' => 'demo', 'consent_text_revision' => '2', 'page_url' => 'https://www.boatingschool.example/demo'],
                'values' => $values + ['email' => $this->email, 'consent' => '1', 'name' => 'Kim Meer', 'school_size' => '11-50'],
                'test_mode' => false,
                'attempt' => 1,
            ]],
        ];
        $process = new Process([PHP_BINARY, base_path('tests/Unit/form-connector-wp-harness.php'), 'send', json_encode($options)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['http'][0];
    }

    public function test_connector_request_is_accepted_and_a_retry_returns_the_same_lead(): void
    {
        $first = $this->connectorRequest();
        $path = (string) parse_url($first['url'], PHP_URL_PATH);
        $this->assertSame('/api/integrations/v1/form-captain/leads', $path);

        $created = $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($first['headers']), $first['body'])
            ->assertCreated()->assertJsonPath('status', 'new');

        // Antwort ging verloren: Form Captain wiederholt den Job; der Connector baut ihn neu (neue Nonce, gleicher Body).
        $retry = $this->connectorRequest();
        $this->assertSame($first['body'], $retry['body']);
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($retry['headers']), $retry['body'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('lead_id', $created->json('lead_id'));

        // Dieselbe Anfrage noch einmal (gleiche Nonce): Replay-Schutz.
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($retry['headers']), $retry['body'])
            ->assertStatus(409)->assertJsonPath('code', 'replay_detected');

        $leads = $this->onAdmin(fn () => FormCaptainLead::where('entry_id', $this->entryId)->get());
        $this->assertCount(1, $leads);
        $this->assertSame('11-50', $leads[0]->school_size);
        $this->assertSame('https://www.boatingschool.example/demo', $leads[0]->page_url);
    }

    public function test_support_lead_from_the_connector_stores_product_and_customer_reference(): void
    {
        $request = $this->connectorRequest(['purpose' => 'support', 'customer_reference' => '1'], ['product' => '{"product_id":42,"customer_id":17}'], [
            'active' => true,
            'customers' => ['17' => ['first' => 'Kim', 'last' => 'Meer', 'email' => 'kim.konto@example.test']],
            'products' => ['42' => ['name' => 'SBF-See Onlinekurs', 'sku' => 'SBF-SEE']],
        ]);
        $id = $this->call('POST', (string) parse_url($request['url'], PHP_URL_PATH), [], [], [], $this->transformHeadersToServerVars($request['headers']), $request['body'])
            ->assertCreated()->json('lead_id');

        $lead = $this->onAdmin(fn () => FormCaptainLead::where('public_id', $id)->firstOrFail());
        $this->assertSame(['support', 42, 'SBF-See Onlinekurs', 'SBF-SEE', 17, 'kim.konto@example.test'],
            [$lead->purpose, $lead->product_id, $lead->product_name, $lead->product_sku, $lead->customer_ref_id, $lead->customer_email]);
    }
}
