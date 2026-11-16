<?php

namespace Tests\Feature\Workflow;

use App\Commerce\Outbox;
use App\Models\CommerceShopAccount;
use App\Models\WorkflowEvent;
use App\Workflow\EventCatalog;
use App\Workflow\WorkflowDispatcher;
use App\Workflow\WorkflowSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * WF-CUST1-02, -09: Zustellung des Ausgangs mit Signatur, Wiederholung,
 * Timeout nach erfolgreichem Aufruf, endgültigem Fehler und Outbox-Replay.
 */
class WorkflowDispatcherTest extends WorkflowTestCase
{
    private function pendingEvent(): WorkflowEvent
    {
        $this->linkedAccount();
        $event = $this->events(EventCatalog::SHOP_ACCOUNT_LINKED)->first();
        // Nur dieses Ereignis zustellen (das der Registrierung als erledigt markieren).
        $this->onAdmin(fn () => DB::table('workflow_event')->where('id', '!=', $event->id)->where('status', 'pending')->update(['status' => 'done']));

        return $event;
    }

    private function fresh(WorkflowEvent $event): WorkflowEvent
    {
        return WorkflowEvent::on('pgsql_admin')->findOrFail($event->id);
    }

    public function test_events_are_delivered_signed_with_their_stable_id_and_only_allowed_data(): void
    {
        $event = $this->pendingEvent();
        Http::fake([self::ENDPOINT => Http::response(['result' => 'captured'])]);

        $this->assertSame(1, app(WorkflowDispatcher::class)->process());

        Http::assertSent(function (Request $request) use ($event) {
            $body = $request->body();
            $h = fn ($n) => $request->header($n)[0] ?? '';
            $decoded = json_decode($body, true);

            return $request->url() === self::ENDPOINT
                && $h(WorkflowSignature::HEADER_KEY) === 'app-workflow'
                && WorkflowSignature::verify(self::OUTBOUND_SECRET, $h(WorkflowSignature::HEADER_TIMESTAMP), $h(WorkflowSignature::HEADER_NONCE), $body, $h(WorkflowSignature::HEADER_SIGNATURE))
                && ! WorkflowSignature::verify(self::WORKFLOW_SECRET, $h(WorkflowSignature::HEADER_TIMESTAMP), $h(WorkflowSignature::HEADER_NONCE), $body, $h(WorkflowSignature::HEADER_SIGNATURE))
                && $h('Idempotency-Key') === $event->id
                && $decoded['op'] === 'event' && $decoded['contract_version'] === '1.0.0'
                && $decoded['event']['id'] === $event->id && $decoded['event']['type'] === 'shop_account.linked' && $decoded['event']['version'] === 1
                && $decoded['event']['scope'] === 'tenant' && $decoded['event']['tenant_id'] === $event->tenant_ref
                && array_keys($decoded['event']['data']) == array_keys($event->payload)
                && ! str_contains($body, '@');
        });
        $fresh = $this->fresh($event);
        $this->assertSame(['done', 1, null], [$fresh->status, $fresh->attempts, $fresh->last_error]);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_a_timeout_after_a_successful_call_is_resent_with_the_same_id_and_counts_as_delivered(): void
    {
        $event = $this->pendingEvent();
        $ids = [];
        Http::fake(function (Request $request) use (&$ids) {
            $ids[] = json_decode($request->body(), true)['event']['id'];
            if (count($ids) === 1) {
                // Der Connector hat angenommen, die Antwort kam nicht mehr an.
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['result' => 'duplicate'], 409);
        });

        app(WorkflowDispatcher::class)->process();
        $this->assertSame(['pending', 'timeout'], [$this->fresh($event)->status, $this->fresh($event)->last_error]);
        $this->onAdmin(fn () => DB::table('workflow_event')->where('id', $event->id)->update(['available_at' => now()->subSecond()]));

        app(WorkflowDispatcher::class)->process();
        $this->assertSame([$event->id, $event->id], $ids);
        $this->assertSame(['done', 2], [$this->fresh($event)->status, $this->fresh($event)->attempts]);
    }

    public function test_temporary_failures_are_retried_with_backoff_until_failed_and_can_be_replayed(): void
    {
        config(['workflow.backoff_minutes' => [1, 5]]);
        $event = $this->pendingEvent();
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['code' => 'connector_not_enabled', 'detail' => 'pia@example.test'], 503)
            ->push([], 500)
            ->push([], 502)
            ->push(['result' => 'duplicate'], 200)]);

        foreach ([['pending', 1, 'http_503:connector_not_enabled'], ['pending', 2, 'http_500'], ['failed', 3, 'http_502']] as [$status, $attempts, $error]) {
            app(WorkflowDispatcher::class)->process();
            $fresh = $this->fresh($event);
            $this->assertSame([$status, $attempts, $error], [$fresh->status, $fresh->attempts, $fresh->last_error]);
            $this->onAdmin(fn () => DB::table('workflow_event')->where('id', $event->id)->whereNotNull('available_at')->update(['available_at' => now()->subSecond()]));
        }
        $this->assertSame(0, app(WorkflowDispatcher::class)->process(), 'fehlgeschlagen bleibt liegen');

        // Outbox-Replay: dieselbe Event-ID erneut einreihen; der Connector erkennt das Doppel.
        $this->assertSame(0, Artisan::call('app:workflow-outbox', ['--requeue' => $event->id]));
        $this->assertSame('done', $this->fresh($event)->status);
        Http::assertSentCount(4);
        $this->assertSame(1, Artisan::call('app:workflow-outbox', ['--requeue' => $event->id]), 'nur fehlgeschlagene lassen sich erneut einreihen');
    }

    public function test_a_permanent_rejection_fails_at_once_without_echoing_the_response(): void
    {
        $event = $this->pendingEvent();
        Http::fake([self::ENDPOINT => Http::response(['code' => 'Unknown_Type!', 'detail' => 'geheim'], 422)]);
        app(WorkflowDispatcher::class)->process();
        $fresh = $this->fresh($event);
        $this->assertSame(['failed', 1, 'rejected:unknown_type'], [$fresh->status, $fresh->attempts, $fresh->last_error]);
    }

    public function test_stuck_sends_count_as_a_failed_attempt_and_nothing_is_sent_without_configuration(): void
    {
        $event = $this->pendingEvent();
        $this->onAdmin(fn () => DB::table('workflow_event')->where('id', $event->id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(11)]));
        $this->assertSame(1, app(WorkflowDispatcher::class)->recoverStuck());
        $this->assertSame(['pending', 'stuck', 1], [$this->fresh($event)->status, $this->fresh($event)->last_error, $this->fresh($event)->attempts]);

        Http::fake();
        config(['workflow.outbound_secret' => '']);
        $this->onAdmin(fn () => DB::table('workflow_event')->where('id', $event->id)->update(['available_at' => now()->subSecond()]));
        $this->assertSame(0, app(WorkflowDispatcher::class)->process());
        Http::assertNothingSent();
    }

    public function test_provisioning_of_a_new_school_reports_failed_then_succeeded(): void
    {
        Mail::fake();
        config(['commerce.shop_url' => 'https://shop.example.test', 'commerce.outbound_secret' => 'commerce-out-secret-1']);
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $account = Outbox::provisionTenant($tenant, $owner);
        $this->assertNotNull($account);
        Http::fake(['https://shop.example.test/*' => Http::sequence()->push(['message' => 'down'], 500)->push(['customer_id' => 777], 200)]);

        app(Outbox::class)->process();
        $failed = $this->events(EventCatalog::PROVISIONING_FAILED);
        $this->assertCount(1, $failed);
        $this->assertEquals(['shop_account_id' => $account->id, 'tenant_id' => $tenant->id, 'attempts' => 1, 'final' => false], $failed[0]->payload);

        $this->onAdmin(fn () => DB::table('commerce_event')->where('shop_account_id', $account->id)->update(['available_at' => now()->subSecond()]));
        app(Outbox::class)->process();
        $succeeded = $this->events(EventCatalog::PROVISIONING_SUCCEEDED);
        $this->assertCount(1, $succeeded);
        $this->assertEquals(['shop_account_id' => $account->id, 'tenant_id' => $tenant->id, 'status' => 'done'], $succeeded[0]->payload);
        $this->assertSame('done', $this->onAdmin(fn () => CommerceShopAccount::find($account->id)->provisioning_status));
        $this->assertStringNotContainsString($owner->email, json_encode($this->events()->map->envelope()));
        $this->onAdmin(fn () => DB::table('commerce_event')->where('shop_account_id', $account->id)->delete());
        $this->onAdmin(fn () => CommerceShopAccount::whereKey($account->id)->delete());
    }
}
