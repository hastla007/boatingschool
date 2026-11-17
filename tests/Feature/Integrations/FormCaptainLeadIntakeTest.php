<?php

namespace Tests\Feature\Integrations;

use App\Commerce\Signature;
use App\Integrations\FormCaptain\LeadSignature;
use App\Models\FormCaptainLead;
use App\Models\FormCaptainLeadRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lead-Eingang für Form Captain (BS-08, BS-13; Vertrag form-captain-lead/1,
 * docs/integrations/form-captain-leads.md): HMAC, Zeitfenster, Replay,
 * Schema, Idempotenz (auch nach einem Timeout nach erfolgreichem Schreiben),
 * Schlüsselrotation, Drosselung -- und dass kein Konto, keine Schule entsteht.
 */
class FormCaptainLeadIntakeTest extends TestCase
{
    use InteractsWithTenants;

    private const PATH = '/api/integrations/v1/form-captain/leads';

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = 'https://www-'.Str::lower(Str::random(8)).'.example.test';
        config([
            'form_captain.keys' => ['fc-lead-2026-11' => 'lead-secret-aaaaaaaaaaaaaaaa'],
            'form_captain.revoked_key_ids' => [],
            'form_captain.allowed_site_urls' => [],
            // Der Commerce-Schlüssel darf hier nie gelten.
            'commerce.keys' => ['shop' => 'commerce-secret-123'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $ids = FormCaptainLead::where('site_url', $this->site)->pluck('public_id');
            FormCaptainLeadRequest::whereIn('lead_public_id', $ids)->delete();
            FormCaptainLead::where('site_url', $this->site)->delete();
        });
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function lead(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema' => 'form-captain-lead/1',
            'source' => ['site_url' => $this->site, 'page_url' => $this->site.'/demo', 'form_id' => 7, 'entry_id' => random_int(1, 1_000_000), 'plugin_version' => '1.0.0'],
            'purpose' => 'demo',
            'contact' => ['name' => 'Kim Meer', 'email' => 'kim-'.Str::lower(Str::random(6)).'@example.test', 'organisation' => 'Segelschule Meer'],
            'school_size' => '3-10',
            'message' => 'Bitte eine Demo.',
            'consent' => ['given_at' => '2026-11-16T10:00:00Z', 'text_revision' => 3, 'text_hash' => str_repeat('a', 64)],
            'submitted_at' => '2026-11-16T10:00:05Z',
        ], $overrides);
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $path, string $body, array $headers = [], string $keyId = 'fc-lead-2026-11', string $secret = 'lead-secret-aaaaaaaaaaaaaaaa', ?int $timestamp = null, ?string $nonce = null): TestResponse
    {
        $signed = LeadSignature::headers($keyId, $secret, $method, $path, $body, $timestamp, $nonce);

        return $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars($headers + $signed + ['Content-Type' => 'application/json']), $body);
    }

    private function postLead(array $lead, ?string $key = null, array $extra = []): TestResponse
    {
        $key ??= 'form-captain:1:'.$lead['source']['entry_id'].':boatingschool_form.send_lead:a_'.Str::lower(Str::random(10));

        return $this->send('POST', self::PATH, json_encode($lead), ['Idempotency-Key' => $key] + $extra);
    }

    private function leadCount(): int
    {
        return $this->onAdmin(fn () => FormCaptainLead::where('site_url', $this->site)->count());
    }

    public function test_signed_lead_is_created_without_any_account_school_or_code(): void
    {
        $users = $this->onAdmin(fn () => User::count());
        $tenants = $this->onAdmin(fn () => Tenant::count());
        $lead = $this->lead();

        $response = $this->postLead($lead)->assertCreated()->assertJsonPath('code', 'created')->assertJsonPath('status', 'new');
        $this->assertMatchesRegularExpression('/^L-[0-9A-Z]{26}$/', $response->json('lead_id'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $stored = $this->onAdmin(fn () => FormCaptainLead::where('public_id', $response->json('lead_id'))->firstOrFail());
        $this->assertSame($lead['contact']['email'], $stored->contact_email);
        $this->assertSame('fc-lead-2026-11', $stored->key_id);
        $this->assertSame(3, $stored->consent_text_revision);
        $this->assertSame(FormCaptainLead::NEW, $stored->status);
        $this->assertTrue($stored->retain_until->isFuture());
        $this->assertSame($users, $this->onAdmin(fn () => User::count()), 'kein Benutzer');
        $this->assertSame($tenants, $this->onAdmin(fn () => Tenant::count()), 'keine Schule');
    }

    public function test_wrong_signature_unknown_key_and_commerce_secret_are_rejected(): void
    {
        $body = json_encode($this->lead());
        $this->send('POST', self::PATH, $body, ['Idempotency-Key' => 'k-1'], secret: 'falsch')->assertStatus(401)->assertJsonPath('code', 'signature_invalid');
        $this->send('POST', self::PATH, $body, ['Idempotency-Key' => 'k-1'], keyId: 'unbekannt')->assertStatus(401)->assertJsonPath('code', 'key_unknown');
        // Der Commerce-Schlüssel ist ein anderer Topf.
        $this->send('POST', self::PATH, $body, ['Idempotency-Key' => 'k-1'], keyId: 'shop', secret: 'commerce-secret-123')->assertStatus(401)->assertJsonPath('code', 'key_unknown');
        // Commerce-Signatur (andere Header) gilt hier nicht.
        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars(Signature::headers('shop', 'commerce-secret-123', 'POST', self::PATH, $body) + ['Content-Type' => 'application/json']), $body)
            ->assertStatus(401);
        // Manipulierter Body nach dem Signieren.
        $signed = LeadSignature::headers('fc-lead-2026-11', 'lead-secret-aaaaaaaaaaaaaaaa', 'POST', self::PATH, $body);
        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars($signed + ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-2']), str_replace('Kim Meer', 'Eve', $body))
            ->assertStatus(401)->assertJsonPath('code', 'signature_invalid');
        $this->assertSame(0, $this->leadCount());
    }

    public function test_no_secret_configured_disables_the_endpoint(): void
    {
        config(['form_captain.keys' => []]);
        $this->postLead($this->lead())->assertStatus(401)->assertJsonPath('code', 'key_unknown');
    }

    public function test_expired_timestamp_and_replayed_nonce(): void
    {
        $lead = $this->lead();
        $this->send('POST', self::PATH, json_encode($lead), ['Idempotency-Key' => 'k-old'], timestamp: time() - 301)
            ->assertStatus(401)->assertJsonPath('code', 'signature_expired');

        $nonce = bin2hex(random_bytes(16));
        $this->send('POST', self::PATH, json_encode($lead), ['Idempotency-Key' => 'k-replay-'.Str::random(6)], nonce: $nonce)->assertCreated();
        $this->send('POST', self::PATH, json_encode($lead), ['Idempotency-Key' => 'k-replay-'.Str::random(6)], nonce: $nonce)
            ->assertStatus(409)->assertJsonPath('code', 'replay_detected');
        $this->assertSame(1, $this->leadCount());
    }

    public function test_schema_errors_name_pointers_but_never_values(): void
    {
        $lead = $this->lead(['purpose' => 'kaufen', 'contact' => ['email' => 'keine-adresse', 'iban' => 'DE89370400440532013000'], 'source' => ['form_id' => '7']]);
        $lead['ip'] = '203.0.113.9';
        unset($lead['consent']);

        $response = $this->postLead($lead)->assertStatus(422)->assertJsonPath('code', 'schema_invalid');
        $pointers = collect($response->json('errors'))->map(fn ($e) => $e['pointer'].' '.$e['code'])->all();
        foreach (['/ip additional_property', '/contact/iban additional_property', '/contact/email format', '/purpose enum', '/source/form_id type', '/consent required'] as $expected) {
            $this->assertContains($expected, $pointers);
        }
        $raw = $response->getContent();
        foreach (['DE89370400440532013000', '203.0.113.9', 'keine-adresse', 'kaufen'] as $value) {
            $this->assertStringNotContainsString($value, $raw);
        }

        $this->postLead($this->lead(['schema' => 'form-captain-lead/2']))->assertStatus(422)->assertJsonPath('errors.0.pointer', '/schema');
        $this->postLead($this->lead(['consent' => ['given_at' => '2026-02-30T10:00:00Z']]))->assertStatus(422)->assertJsonPath('errors.0.pointer', '/consent/given_at');
        $this->send('POST', self::PATH, '{kein json', ['Idempotency-Key' => 'k-json'])->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->send('POST', self::PATH, json_encode($this->lead()))->assertStatus(400)->assertJsonPath('code', 'bad_request'); // ohne Idempotency-Key
        $this->send('POST', self::PATH, json_encode($this->lead(['message' => str_repeat('x', 17000)])), ['Idempotency-Key' => 'k-big'])->assertStatus(400);
        $this->assertSame(0, $this->leadCount());
    }

    public function test_duplicate_delivery_and_timeout_after_write_return_the_same_lead(): void
    {
        $lead = $this->lead();
        $key = 'form-captain:1:'.$lead['source']['entry_id'].':boatingschool_form.send_lead:a_0123456789';
        $first = $this->postLead($lead, $key)->assertCreated();
        // Die Antwort ging verloren (Timeout); Form Captain wiederholt den Job mit neuer Nonce.
        $retry = $this->postLead($lead, $key)->assertOk()->assertJsonPath('code', 'created')->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('lead_id'), $retry->json('lead_id'));
        $this->assertSame(1, $this->leadCount());

        // Gleicher Schlüssel, anderer Inhalt.
        $changed = $lead;
        $changed['message'] = 'Anderer Text';
        $this->postLead($changed, $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

        // Nach dem Löschen legt eine späte Wiederholung den Lead nicht neu an.
        $this->onAdmin(fn () => FormCaptainLead::where('public_id', $first->json('lead_id'))->delete());
        $this->postLead($lead, $key)->assertOk()->assertJsonPath('lead_id', $first->json('lead_id'))->assertJsonPath('status', 'deleted');
        $this->assertSame(0, $this->leadCount());
    }

    public function test_key_rotation_accepts_both_keys_and_revoked_key_is_403(): void
    {
        config(['form_captain.keys' => ['fc-lead-2026-11' => 'lead-secret-aaaaaaaaaaaaaaaa', 'fc-lead-2026-12' => 'lead-secret-bbbbbbbbbbbbbbbb']]);
        $lead = $this->lead();
        $key = 'form-captain:1:'.$lead['source']['entry_id'].':boatingschool_form.send_lead:a_rotation01';
        $first = $this->postLead($lead, $key)->assertCreated();
        // Wiederholung mit dem neuen Schlüssel: derselbe Lead.
        $this->send('POST', self::PATH, json_encode($lead), ['Idempotency-Key' => $key], 'fc-lead-2026-12', 'lead-secret-bbbbbbbbbbbbbbbb')
            ->assertOk()->assertJsonPath('lead_id', $first->json('lead_id'));
        $this->send('POST', '/api/integrations/v1/form-captain/ping', '{}', [], 'fc-lead-2026-12', 'lead-secret-bbbbbbbbbbbbbbbb')
            ->assertOk()->assertJson(['ok' => true, 'schema' => 'form-captain-lead/1', 'key_id' => 'fc-lead-2026-12']);
        $this->assertNotNull(Cache::get('form-captain-key-last-used:fc-lead-2026-12'));

        // Alter Schlüssel widerrufen.
        config(['form_captain.revoked_key_ids' => ['fc-lead-2026-11']]);
        $this->postLead($this->lead())->assertStatus(403)->assertJsonPath('code', 'key_revoked');
        $this->assertSame(1, $this->leadCount());
    }

    public function test_rate_limit_per_key_and_per_email(): void
    {
        RateLimiter::clear('form-captain-lead:minute:fc-lead-2026-11');
        config(['form_captain.rate_limits.per_key_minute' => 2]);
        $this->send('POST', '/api/integrations/v1/form-captain/ping', '{}')->assertOk();
        $this->send('POST', '/api/integrations/v1/form-captain/ping', '{}')->assertOk();
        $limited = $this->send('POST', '/api/integrations/v1/form-captain/ping', '{}')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        RateLimiter::clear('form-captain-lead:minute:fc-lead-2026-11');
        config(['form_captain.rate_limits.per_key_minute' => 60]);

        // Je Absenderadresse höchstens 2 neue Leads am Tag (Test), danach 202 mit der bestehenden lead_id.
        config(['form_captain.rate_limits.per_email_day' => 2]);
        $email = 'viel-'.Str::lower(Str::random(6)).'@example.test';
        $ids = [];
        foreach ([1, 2] as $i) {
            $ids[] = $this->postLead($this->lead(['contact' => ['email' => $email]]))->assertCreated()->json('lead_id');
        }
        $merged = $this->postLead($this->lead(['contact' => ['email' => strtoupper($email)]]))->assertStatus(202)->assertJsonPath('code', 'accepted_merged');
        $this->assertSame($ids[1], $merged->json('lead_id'));
        $this->assertSame(2, $this->leadCount());
        $this->assertSame(1, $this->onAdmin(fn () => FormCaptainLead::where('public_id', $ids[1])->value('merged_count')));
        RateLimiter::clear('form-captain-lead:minute:fc-lead-2026-11');
        RateLimiter::clear('form-captain-lead:day:fc-lead-2026-11');
    }

    public function test_ping_creates_nothing_and_delete_is_idempotent(): void
    {
        $this->send('POST', '/api/integrations/v1/form-captain/ping', '{}')->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, $this->leadCount());

        $id = $this->postLead($this->lead())->assertCreated()->json('lead_id');
        $this->send('DELETE', '/api/integrations/v1/form-captain/leads/'.$id, '')->assertNoContent();
        $this->send('DELETE', '/api/integrations/v1/form-captain/leads/'.$id, '')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame(0, $this->leadCount());
        // Unsigniert: keine Löschung, kein Hinweis auf vorhandene Leads.
        $this->call('DELETE', '/api/integrations/v1/form-captain/leads/'.$id)->assertStatus(401);
    }

    public function test_site_allowlist(): void
    {
        config(['form_captain.allowed_site_urls' => ['https://boatingschool.example']]);
        $this->postLead($this->lead())->assertStatus(403)->assertJsonPath('code', 'site_not_allowed');
        config(['form_captain.allowed_site_urls' => [$this->site.'/']]);
        $this->postLead($this->lead())->assertCreated();
    }

    public function test_support_lead_carries_product_and_customer_reference_only_for_purpose_support(): void
    {
        $support = ['purpose' => 'support', 'product' => ['id' => 42, 'name' => 'SBF-See Onlinekurs', 'sku' => 'SBF-SEE'], 'customer' => ['id' => 17, 'email' => 'kunde@example.test']];
        $id = $this->postLead($this->lead($support))->assertCreated()->json('lead_id');
        $stored = $this->onAdmin(fn () => FormCaptainLead::where('public_id', $id)->firstOrFail());
        $this->assertSame(['support', 42, 'SBF-See Onlinekurs', 'SBF-SEE', 17, 'kunde@example.test'],
            [$stored->purpose, $stored->product_id, $stored->product_name, $stored->product_sku, $stored->customer_ref_id, $stored->customer_email]);

        // Freitext ohne Anmeldung: nur der Name.
        $this->postLead($this->lead(['purpose' => 'support', 'product' => ['name' => 'Funkkurs']]))->assertCreated();

        // Datenschutz: bei anderen Zwecken abgelehnt, ohne Werte in der Antwort.
        $response = $this->postLead($this->lead(['purpose' => 'pricing'] + array_diff_key($support, ['purpose' => 1])))->assertStatus(422);
        $pointers = collect($response->json('errors'))->map(fn ($e) => $e['pointer'].' '.$e['code'])->all();
        $this->assertContains('/product purpose_mismatch', $pointers);
        $this->assertContains('/customer purpose_mismatch', $pointers);
        $this->assertStringNotContainsString('kunde@example.test', $response->getContent());
        $this->postLead($this->lead(['purpose' => 'support', 'product' => ['id' => 42], 'customer' => ['email' => 'x@example.test', 'iban' => 'DE00']]))
            ->assertStatus(422)->assertJsonFragment(['pointer' => '/product/name', 'code' => 'required'])->assertJsonFragment(['pointer' => '/customer/iban', 'code' => 'additional_property']);
    }

    public function test_all_owner_purposes_are_accepted_and_legacy_values_are_rewritten(): void
    {
        foreach (['support', 'demo', 'consulting', 'school_access', 'pricing'] as $purpose) {
            $this->postLead($this->lead(['purpose' => $purpose]))->assertCreated();
        }
        $id = $this->postLead($this->lead(['purpose' => 'consultation']))->assertCreated()->json('lead_id');
        $this->assertSame('consulting', $this->onAdmin(fn () => FormCaptainLead::where('public_id', $id)->value('purpose')));
        $this->postLead($this->lead(['purpose' => 'kaufen']))->assertStatus(422);
    }

    public function test_query_parameters_are_not_accepted(): void
    {
        $body = json_encode($this->lead());
        $signed = LeadSignature::headers('fc-lead-2026-11', 'lead-secret-aaaaaaaaaaaaaaaa', 'POST', self::PATH, $body);
        $this->call('POST', self::PATH.'?x=1', [], [], [], $this->transformHeadersToServerVars($signed + ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-q']), $body)
            ->assertStatus(400)->assertJsonPath('code', 'bad_request');
    }
}
