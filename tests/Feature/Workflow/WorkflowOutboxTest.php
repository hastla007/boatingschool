<?php

namespace Tests\Feature\Workflow;

use App\Models\CommerceShopAccount;
use App\Models\Coupon;
use App\Workflow\DomainEvents;
use App\Workflow\EventCatalog;
use App\Workflow\PayloadAllowlist;
use App\Workflow\WorkflowOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WF-CUST1-02 bis -04: Ereignisse entstehen in der Transaktion der
 * fachlichen Änderung, mit stabiler Event-ID und nur mit erlaubten Feldern.
 */
class WorkflowOutboxTest extends WorkflowTestCase
{
    public function test_shop_flows_record_versioned_events_with_only_allowlisted_fields(): void
    {
        $email = 'pia-'.Str::lower(Str::random(6)).'@example.test';
        [$tenant, , $customerId, $account] = $this->linkedAccount([
            'email' => $email, 'first_name' => 'Pia', 'last_name' => 'Meer', 'vat_id' => 'DE123456789', 'billing_country' => 'DE',
        ]);
        // Wiederholte Registrierung des Shops: kein zweites Ereignis.
        $this->commerce('POST', '/accounts', ['shop_customer_id' => $customerId, 'email' => $email])->assertOk();

        $registered = $this->events(EventCatalog::SHOP_ACCOUNT_REGISTERED);
        $this->assertCount(1, $registered);
        $this->assertEquals(['shop_account_id' => $account->id, 'status' => 'pending_review'], $registered[0]->payload);
        $this->assertSame(['platform', null, 1], [$registered[0]->scope, $registered[0]->tenant_ref, $registered[0]->version]);

        $linked = $this->events(EventCatalog::SHOP_ACCOUNT_LINKED);
        $this->assertCount(1, $linked);
        $this->assertSame(['tenant', $tenant->id], [$linked[0]->scope, $linked[0]->tenant_ref]);
        $this->assertSame('shop_account:'.$account->id, $linked[0]->correlation_id);

        $line = ['shop_customer_id' => $customerId, 'order_id' => '7001', 'order_line_id' => '3', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 2];
        $batchId = $this->commerce('POST', '/orders/lines', $line)->assertCreated()->json('data.batch_id');
        $this->commerce('POST', '/orders/lines', $line)->assertOk(); // Wiederholung nach Timeout
        $delivered = $this->events(EventCatalog::CODE_BATCH_DELIVERED);
        $this->assertCount(1, $delivered);
        $this->assertEquals([
            'batch_id' => $batchId, 'tenant_id' => $tenant->id, 'shop_account_id' => $account->id, 'order_id' => '7001', 'order_line_id' => '3',
            'kind' => 'course', 'quantity' => 2, 'course_key' => 'SRC', 'product_key' => null,
        ], $delivered[0]->payload);

        // Rückerstattung einer Position: Sperre gehört dem WooCommerce-Connector,
        // das Ereignis meldet nur die Zahlen. Gleicher Storno noch einmal: kein zweites Ereignis.
        $this->commerce('POST', "/batches/{$batchId}/void", ['reason' => 'Rückerstattung an Pia Meer', 'quantity' => 1], ['Idempotency-Key' => 'refund-5-3'])->assertOk();
        $this->commerce('POST', "/batches/{$batchId}/void", ['reason' => 'Rückerstattung an Pia Meer', 'quantity' => 1], ['Idempotency-Key' => 'refund-5-3'])->assertOk();
        $revoked = $this->events(EventCatalog::CODE_BATCH_REVOKED);
        $this->assertCount(1, $revoked);
        $this->assertEquals(['voided' => 1, 'redeemed' => 0, 'open' => 1, 'status' => 'partially_voided'],
            array_intersect_key($revoked[0]->payload, array_flip(['voided', 'redeemed', 'open', 'status'])));
        $this->assertSame($delivered[0]->correlation_id, $revoked[0]->correlation_id, 'Lieferung und Sperre derselben Bestellung gehören zusammen.');

        $this->commerce('POST', "/accounts/{$customerId}/deactivate")->assertOk();
        $this->commerce('POST', "/accounts/{$customerId}/deactivate")->assertOk();
        $this->assertCount(1, $this->events(EventCatalog::SHOP_ACCOUNT_DEACTIVATED));

        // Code-Redaktion: kein Codewert, keine Adresse, kein Name, keine USt-ID, kein Grund.
        $all = json_encode($this->events()->map->envelope());
        $codes = $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $batchId)->pluck('code'));
        $this->assertCount(2, $codes);
        foreach ([...$codes, $email, 'Pia', 'Meer', 'DE123456789', 'Rückerstattung', 'refund-5-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        foreach ($this->events() as $event) {
            foreach (array_keys($event->payload) as $field) {
                $this->assertDoesNotMatchRegularExpression(PayloadAllowlist::FORBIDDEN_NAME, $field);
            }
        }
    }

    public function test_an_awaiting_link_registration_looks_like_pending_review(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->commerce('POST', '/accounts', ['shop_customer_id' => '4711', 'email' => $admin->email])->assertCreated();
        $this->assertSame('awaiting_link', $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->value('status')));
        $this->assertSame('pending_review', $this->events(EventCatalog::SHOP_ACCOUNT_REGISTERED)->first()->payload['status']);
    }

    public function test_a_rolled_back_change_leaves_no_event_and_dispatch_waits_for_the_commit(): void
    {
        config(['workflow.dispatch' => 'sync']);
        Http::fake([self::ENDPOINT => Http::response(['result' => 'captured'])]);
        [, , , $account] = $this->linkedAccount();
        Http::assertSentCount(2); // registriert + verknüpft, je nach Commit zugestellt
        $before = $this->events()->count();

        // Gleiche Verbindung wie die fachliche Transaktion (record() schreibt auf die Standardverbindung).
        try {
            DB::transaction(function () use ($account) {
                DomainEvents::shopAccountDeactivated($account->forceFill(['status' => 'revoked', 'revoked_at' => now()]));
                Http::assertSentCount(2); // noch nicht zugestellt: Transaktion offen
                throw new \RuntimeException('Abbruch');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, $this->events()->count());
        Http::assertSentCount(2);

        DB::transaction(fn () => DomainEvents::shopAccountDeactivated($account));
        Http::assertSentCount(3);
        $this->assertSame('done', $this->events(EventCatalog::SHOP_ACCOUNT_DEACTIVATED)->first()->status);
    }

    public function test_nothing_is_recorded_while_the_contract_is_switched_off(): void
    {
        config(['workflow.enabled' => false]);
        $this->linkedAccount();
        $this->assertCount(0, $this->events());
    }

    public function test_event_ids_are_stable_and_distinguish_real_occurrences(): void
    {
        $a = WorkflowOutbox::eventId('code_batch.delivered', 1, 'code_batch', 'b-1', 'once');
        $this->assertSame($a, WorkflowOutbox::eventId('code_batch.delivered', 1, 'code_batch', 'b-1', 'once'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertNotSame($a, WorkflowOutbox::eventId('code_batch.delivered', 2, 'code_batch', 'b-1', 'once'));
        $this->assertNotSame($a, WorkflowOutbox::eventId('code_batch.delivered', 1, 'code_batch', 'b-2', 'once'));
        $this->assertNotSame($a, WorkflowOutbox::eventId('code_batch.revoked', 1, 'code_batch', 'b-1', 'once'));
    }

    public function test_the_allowlist_drops_unknown_fields_and_rejects_bad_values(): void
    {
        $tenant = (string) Str::uuid();
        $data = PayloadAllowlist::build(EventCatalog::SHOP_ACCOUNT_LINKED, [
            'shop_account_id' => strtoupper((string) Str::uuid()), 'tenant_id' => $tenant, 'status' => 'linked',
            'email' => 'x@example.test', 'link_code' => 'ABCD-1234', 'password' => 'geheim', 'vat_id' => 'DE1', 'first_name' => 'Kim',
        ]);
        $this->assertSame(['shop_account_id', 'tenant_id', 'status'], array_keys($data));
        $this->assertSame(strtolower($data['shop_account_id']), $data['shop_account_id']);

        foreach ([
            [EventCatalog::SHOP_ACCOUNT_LINKED, ['shop_account_id' => (string) Str::uuid(), 'status' => 'linked'], 'payload:tenant_id:required'],
            [EventCatalog::CODE_BATCH_DELIVERED, ['batch_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'quantity' => '3'], 'payload:quantity:invalid'],
            [EventCatalog::CODE_BATCH_DELIVERED, ['batch_id' => 'kein-uuid', 'tenant_id' => $tenant], 'payload:batch_id:invalid'],
            [EventCatalog::CODE_BATCH_DELIVERED, ['batch_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'order_id' => 'a b@c'], 'payload:order_id:invalid'],
            [EventCatalog::SHOP_ACCOUNT_DEACTIVATED, ['shop_account_id' => (string) Str::uuid(), 'status' => 'gelöscht'], 'payload:status:invalid'],
        ] as [$type, $source, $code]) {
            try {
                PayloadAllowlist::build($type, $source);
                $this->fail('erwartet: '.$code);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($code, $e->getMessage());
            }
        }

        // Kein Feld des Katalogs trägt einen verbotenen Namen (auch künftig nicht).
        foreach (EventCatalog::TYPES as $spec) {
            foreach (array_keys($spec['fields']) as $field) {
                $this->assertDoesNotMatchRegularExpression(PayloadAllowlist::FORBIDDEN_NAME, $field);
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $field);
            }
            $this->assertLessThanOrEqual(24, count($spec['fields']));
        }
    }

    public function test_a_failing_record_never_breaks_the_business_transaction(): void
    {
        [, , $customerId, $account] = $this->linkedAccount();
        // Ungültige Quelle (fehlende Schule): das Ereignis entfällt, die Deaktivierung gilt.
        DB::connection('pgsql_admin')->transaction(function () use ($account) {
            $account->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            $this->assertNull(WorkflowOutbox::record(EventCatalog::SHOP_ACCOUNT_LINKED, $account->id, ['shop_account_id' => $account->id, 'status' => 'linked'], 'x', null, 'pgsql_admin'));
        });
        $this->assertSame('revoked', $this->onAdmin(fn () => CommerceShopAccount::find($account->id)->status));
    }
}
