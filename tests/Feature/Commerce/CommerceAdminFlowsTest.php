<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Outbox;
use App\Commerce\Signature;
use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Shop-Anbindung in der App: Superadmin-Prüfung, Ausgang an den Shop, Schuladmin. */
class CommerceAdminFlowsTest extends TestCase
{
    use InteractsWithTenants;

    private string $shopId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopId = 'test-'.Str::lower(Str::random(8));
        config(['commerce.keys' => ['shop' => 'test-secret-123'], 'commerce.shop_id' => $this->shopId]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $ids = CommerceShopAccount::where('shop_id', $this->shopId)->pluck('id');
            CommerceEvent::whereIn('shop_account_id', $ids)->delete();
            CommerceShopAccount::whereIn('id', $ids)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function register(string $customerId, string $email, string $company = 'Segelschule Test'): CommerceShopAccount
    {
        $uri = '/api/commerce/v1/accounts';
        $body = json_encode(['shop_customer_id' => $customerId, 'email' => $email, 'company_name' => $company, 'first_name' => 'Kim', 'last_name' => 'Meer']);
        $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(Signature::headers('shop', 'test-secret-123', 'POST', $uri, $body)
            + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)->assertSuccessful();
        $account = $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->where('shop_customer_id', $customerId)->firstOrFail());
        if ($account->user_id) {
            $this->createdUserIds[] = $account->user_id;
        }

        return $account;
    }

    public function test_superadmin_approves_a_registration_as_a_new_school_and_the_owner_gets_a_setup_link(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $account = $this->register('801', 'neu-'.Str::lower(Str::random(6)).'@example.test');
        $slug = 'shop-'.Str::lower(Str::random(8));

        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index'))->assertOk()->assertSee($account->email)->assertSee('In Prüfung');
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'new', 'name' => 'Segelschule Test', 'slug' => $slug])
            ->assertRedirect()->assertSessionHas('status');

        $account = $this->onAdmin(fn () => $account->fresh());
        $tenant = $this->onAdmin(fn () => Tenant::where('slug', $slug)->firstOrFail());
        $this->createdTenantIds[] = $tenant->id;
        $this->assertSame('linked', $account->status);
        $this->assertSame($tenant->id, $account->tenant_id);
        $this->assertSame('owner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $account->user_id)->value('role')));
        $this->assertTrue($this->onAdmin(fn () => $account->canPurchase()));
        Notification::assertSentTo($this->onAdmin(fn () => User::find($account->user_id)), AccountInvitation::class);
    }

    public function test_superadmin_assigns_to_an_existing_school_as_admin_or_rejects(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $account = $this->register('802', 'zweit-'.Str::lower(Str::random(6)).'@example.test');
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'existing', 'tenant_id' => $tenant->id])->assertRedirect();
        $this->assertSame('admin', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $account->user_id)->value('role')));

        // Bereits geprüft: kein zweites Freigeben.
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'existing', 'tenant_id' => $tenant->id])->assertStatus(409);

        $other = $this->register('803', 'dritt-'.Str::lower(Str::random(6)).'@example.test');
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $other), ['note' => 'keine Bootsschule'])->assertRedirect();
        $this->assertSame('rejected', $this->onAdmin(fn () => $other->fresh()->status));
        // Abgelehnt: auch ein Verknüpfungscode hilft nicht.
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->actingAsInTenant($admin, $tenant)->post('/admin/shop-link-code');
        $uri = '/api/commerce/v1/accounts/803/link';
        $body = json_encode(['link_code' => session('shopLinkCode')]);
        $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(Signature::headers('shop', 'test-secret-123', 'POST', $uri, $body)
            + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_REJECTED');
    }

    public function test_new_school_is_provisioned_in_the_shop_with_retries_and_an_alarm(): void
    {
        Mail::fake();
        config(['commerce.shop_url' => 'https://shop.example.test', 'commerce.outbound_secret' => 'out-secret', 'commerce.alert_email' => 'alarm@example.test']);
        $superadmin = $this->createSuperAdmin();
        $shopUp = false;
        Http::fake(function () use (&$shopUp) {
            return $shopUp ? Http::response(['customer_id' => 4711, 'created' => true], 200) : Http::response(['message' => 'down'], 503);
        });

        $slug = 'prov-'.Str::lower(Str::random(8));
        $this->actingAs($superadmin)->post(route('superadmin.tenants.store'), [
            'slug' => $slug, 'name' => 'Provisionierte Schule', 'status' => 'active',
            'admin_first_name' => 'Pia', 'admin_last_name' => 'Hafen', 'admin_email' => $slug.'@example.test',
        ])->assertRedirect();
        $tenant = $this->onAdmin(fn () => Tenant::where('slug', $slug)->firstOrFail());
        $this->createdTenantIds[] = $tenant->id;
        $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $slug.'@example.test')->value('id'));
        $account = $this->onAdmin(fn () => CommerceShopAccount::where('tenant_id', $tenant->id)->firstOrFail());
        $event = $this->onAdmin(fn () => CommerceEvent::where('shop_account_id', $account->id)->where('direction', 'out')->firstOrFail());
        $this->assertSame(Outbox::PROVISION, $event->type);
        $this->assertSame(1, $event->attempts, 'direkt nach der Antwort versucht');

        // Signiert, ohne Passwort.
        Http::assertSent(fn ($request) => $request->hasHeader(Signature::HEADER_SIGNATURE) && ! str_contains($request->body(), 'password')
            && $request['app_tenant_id'] === $tenant->id);

        // Weitere Versuche nach den Wartezeiten, dann „fehlgeschlagen“ + Alarm.
        $this->travel(2)->minutes();
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $this->travel(6)->minutes();
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $this->assertSame('pending', $this->onAdmin(fn () => $event->fresh()->status), 'noch der Versuch nach 30 Minuten');
        $this->travel(31)->minutes();
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $this->assertSame(4, $this->onAdmin(fn () => $event->fresh()->attempts));
        $this->assertSame('failed', $this->onAdmin(fn () => $event->fresh()->status));
        $this->assertSame('failed', $this->onAdmin(fn () => $account->fresh()->provisioning_status));
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index'))->assertSee('Shopkonto ausstehend/Fehler');

        // Shop wieder erreichbar: erneut anstoßen.
        $shopUp = true;
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.retry', $account))->assertRedirect();
        $account = $this->onAdmin(fn () => $account->fresh());
        $this->assertSame('done', $account->provisioning_status);
        $this->assertSame('4711', $account->shop_customer_id);
        $this->assertTrue($this->onAdmin(fn () => $account->canPurchase()));
    }

    public function test_approval_never_upgrades_memberships_or_binds_by_email(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        // Adresse hat schon ein App-Konto: nur per Verknüpfungscode, nicht per Freigabe.
        $learner = $this->createTenantUser($tenant, 'learner');
        $byEmail = $this->register('811', $learner->email);
        $this->assertSame('awaiting_link', $byEmail->status);
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $byEmail), ['mode' => 'existing', 'tenant_id' => $tenant->id])->assertStatus(409);
        $this->assertSame('learner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('role')));

        // Neu registrierte Person, die die Schule inzwischen als Lernende eingetragen hat:
        // keine stille Hochstufung zur Admin.
        $account = $this->register('812', 'neu-'.Str::lower(Str::random(6)).'@example.test');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $account->user_id, 'role' => 'learner', 'status' => 'active']));
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'existing', 'tenant_id' => $tenant->id])
            ->assertSessionHasErrors('tenant_id');
        $this->assertSame('learner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $account->user_id)->value('role')));
        $this->assertSame('pending_review', $this->onAdmin(fn () => $account->fresh()->status));

        // Ablehnen entfernt das nie benutzte, bei der Registrierung angelegte App-Konto
        // (die Adresse ist danach wieder frei) -- hier nicht, weil die Person schon
        // Lernende der Schule ist.
        $unused = $this->register('813', 'frei-'.Str::lower(Str::random(6)).'@example.test');
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $unused))->assertRedirect();
        $this->assertNull($this->onAdmin(fn () => User::find($unused->user_id)), 'unbenutztes App-Konto entfernt');
        $this->assertNull($this->onAdmin(fn () => $unused->fresh()->user_id));

        // Bereits abgelehnt: nicht noch einmal ablehnen/freigeben.
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $account))->assertRedirect();
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $account))->assertStatus(409);
    }

    public function test_existing_shop_customer_with_the_same_email_is_not_taken_over(): void
    {
        config(['commerce.shop_url' => 'https://shop.example.test', 'commerce.outbound_secret' => 'out-secret']);
        Http::fake(fn () => Http::response(['customer_id' => 4712, 'created' => false, 'matched_by' => 'email'], 200));
        $superadmin = $this->createSuperAdmin();
        $slug = 'mail-'.Str::lower(Str::random(8));
        $this->actingAs($superadmin)->post(route('superadmin.tenants.store'), [
            'slug' => $slug, 'name' => 'Schule mit Shopkonto', 'status' => 'active',
            'admin_first_name' => 'Ole', 'admin_last_name' => 'Kai', 'admin_email' => $slug.'@example.test',
        ])->assertRedirect();
        $tenant = $this->onAdmin(fn () => Tenant::where('slug', $slug)->firstOrFail());
        $this->createdTenantIds[] = $tenant->id;
        $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $slug.'@example.test')->value('id'));

        $account = $this->onAdmin(fn () => CommerceShopAccount::where('tenant_id', $tenant->id)->firstOrFail());
        $this->assertSame('needs_link', $account->provisioning_status);
        $this->assertNull($account->shop_customer_id, 'E-Mail-Gleichheit verknüpft nie');
        $this->assertSame('done', $this->onAdmin(fn () => CommerceEvent::where('shop_account_id', $account->id)->value('status')));
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'all']))->assertSee('bereits ein Kundenkonto mit dieser Adresse');
    }

    public function test_school_admin_sees_linked_accounts_and_can_unlink(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $account = $this->onAdmin(fn () => CommerceShopAccount::create([
            'shop_id' => $this->shopId, 'shop_customer_id' => '901', 'user_id' => $admin->id, 'tenant_id' => $tenant->id,
            'status' => 'linked', 'email' => 'einkauf@example.test', 'linked_at' => now(),
        ]));

        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons')->assertOk()->assertSee('einkauf@example.test')->assertSee('Shop verknüpfen');
        $this->actingAsInTenant($admin, $tenant)->delete("/admin/shop-accounts/{$account->id}")->assertRedirect();
        $this->assertSame('revoked', $this->onAdmin(fn () => $account->fresh()->status));

        // Fremde Schule: nicht auffindbar.
        $other = $this->createTestTenant('Andere');
        $otherAdmin = $this->createTenantUser($other, 'admin');
        $this->actingAsInTenant($otherAdmin, $other)->delete("/admin/shop-accounts/{$account->id}")->assertNotFound();
    }

    /** Schule mit Inhaber und vorgemerktem Ausgangs-Ereignis (noch nicht gesendet). */
    private function pendingProvisioning(): array
    {
        config(['commerce.shop_url' => 'https://shop.example.test', 'commerce.outbound_secret' => 'out-secret', 'commerce.alert_email' => 'alarm@example.test']);
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $account = $this->onAdmin(fn () => Outbox::provisionTenant($tenant, $owner));
        $event = $this->onAdmin(fn () => CommerceEvent::where('shop_account_id', $account->id)->where('direction', 'out')->firstOrFail());

        return [$tenant, $owner, $account, $event];
    }

    public function test_outbox_claims_before_sending_and_holds_no_transaction_or_lock_during_the_http_call(): void
    {
        [, , $account, $event] = $this->pendingProvisioning();
        // Zweite Datenbanksitzung (wie ein zweiter Worker).
        config(['database.connections.pgsql_probe' => config('database.connections.pgsql_admin')]);
        $observed = [];
        Http::fake(function () use ($event, &$observed) {
            $probe = DB::connection('pgsql_probe');
            $observed = [
                'transaction_level' => DB::connection('pgsql_admin')->transactionLevel(),
                'status_seen_elsewhere' => $probe->table('commerce_event')->where('id', $event->id)->value('status'),
                // Nicht gesperrt: eine andere Sitzung kommt sofort an die Zeile.
                'lockable' => $probe->transaction(fn () => $probe->selectOne('SELECT id FROM commerce_event WHERE id = ? FOR UPDATE NOWAIT', [$event->id])) !== null,
                // Ein zweiter Worker kann das Ereignis nicht noch einmal beanspruchen.
                'second_claim' => (new Outbox)->claim($event->id),
            ];

            return Http::response(['customer_id' => 4901, 'created' => true, 'matched_by' => 'created'], 201);
        });

        $this->assertTrue($this->onAdmin(fn () => app(Outbox::class)->processOne($event->id)));
        DB::purge('pgsql_probe');

        $this->assertSame(0, $observed['transaction_level']);
        $this->assertSame('sending', $observed['status_seen_elsewhere']);
        $this->assertTrue($observed['lockable']);
        $this->assertFalse($observed['second_claim']);
        Http::assertSentCount(1);
        $event = $this->onAdmin(fn () => $event->fresh());
        $this->assertSame('done', $event->status);
        $this->assertNull($event->claimed_at);
        $this->assertSame('4901', $this->onAdmin(fn () => $account->fresh()->shop_customer_id));
    }

    public function test_concurrent_claims_send_only_once(): void
    {
        [, , , $event] = $this->pendingProvisioning();
        Http::fake(fn () => Http::response(['customer_id' => 4902, 'created' => true], 201));
        $outbox = new Outbox;

        // Zwei Worker greifen gleichzeitig zu: nur einer bekommt das Ereignis.
        $this->assertTrue($this->onAdmin(fn () => $outbox->claim($event->id)));
        $this->assertFalse($this->onAdmin(fn () => $outbox->claim($event->id)));
        $this->assertNotNull($this->onAdmin(fn () => $event->fresh()->claimed_at));
        // Scheduler und „Erneut versuchen“ laufen in diesem Moment: nichts gesendet.
        $this->assertFalse($this->onAdmin(fn () => $outbox->processOne($event->id)));
        $this->assertSame(0, $this->onAdmin(fn () => $outbox->process()));
        Http::assertNothingSent();
        $this->assertSame('sending', $this->onAdmin(fn () => $event->fresh()->status));

        // Noch nicht fällig: auch nicht beanspruchbar.
        $this->onAdmin(fn () => CommerceEvent::whereKey($event->id)->update(['status' => 'pending', 'claimed_at' => null, 'available_at' => now()->addMinute()]));
        $this->assertFalse($this->onAdmin(fn () => $outbox->claim($event->id)));
    }

    public function test_events_stuck_in_sending_are_recovered_and_eventually_fail_with_an_alarm(): void
    {
        Mail::fake();
        [, , $account, $event] = $this->pendingProvisioning();
        $shopUp = false;
        Http::fake(function () use (&$shopUp) {
            return $shopUp ? Http::response(['customer_id' => 4903, 'created' => true], 201) : Http::response(['message' => 'down'], 503);
        });

        // Gerade erst beansprucht (anderer Worker sendet noch): bleibt unangetastet.
        $this->onAdmin(fn () => CommerceEvent::whereKey($event->id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(Outbox::STUCK_MINUTES - 1)]));
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $this->assertSame('sending', $this->onAdmin(fn () => $event->fresh()->status));
        Http::assertNothingSent();

        // Prozess abgebrochen: nach 10 Minuten zählt es als Fehlversuch und wird wiederholt.
        $this->travel(2)->minutes();
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $fresh = $this->onAdmin(fn () => $event->fresh());
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->claimed_at);
        $this->assertStringContainsString('Keine Rückmeldung', $fresh->last_error);
        $this->assertSame('pending', $this->onAdmin(fn () => $account->fresh()->provisioning_status));
        Http::assertNothingSent();

        $shopUp = true;
        $this->travel(2)->minutes();
        $this->artisan('app:commerce-outbox')->assertSuccessful();
        $this->assertSame('done', $this->onAdmin(fn () => $event->fresh()->status));
        $this->assertSame('done', $this->onAdmin(fn () => $account->fresh()->provisioning_status));

        // Hängt der letzte Versuch: „fehlgeschlagen“ mit Alarm statt endlos neu.
        [, , $account2, $event2] = $this->pendingProvisioning();
        $this->onAdmin(fn () => CommerceEvent::whereKey($event2->id)->update(['status' => 'sending', 'attempts' => 3, 'claimed_at' => now()->subMinutes(30)]));
        $this->assertSame(1, $this->onAdmin(fn () => app(Outbox::class)->recoverStuck()));
        $this->assertSame('failed', $this->onAdmin(fn () => $event2->fresh()->status));
        $this->assertSame('failed', $this->onAdmin(fn () => $account2->fresh()->provisioning_status));
    }

    public function test_provisioning_takes_over_a_self_registered_shop_account(): void
    {
        [$tenant, $owner, $account, $event] = $this->pendingProvisioning();
        // Die Person hatte sich vorher selbst im Shop registriert (Kunde 4904).
        $existing = $this->register('4904', 'selbst-'.Str::lower(Str::random(6)).'@example.test');
        Http::fake(fn () => Http::response(['customer_id' => 4904, 'created' => false, 'matched_by' => 'app_user'], 200));

        $this->assertTrue($this->onAdmin(fn () => app(Outbox::class)->processOne($event->id)));

        $existing = $this->onAdmin(fn () => $existing->fresh());
        $this->assertSame('linked', $existing->status);
        $this->assertSame($tenant->id, $existing->tenant_id);
        $this->assertSame($owner->id, $existing->user_id);
        $this->assertSame('done', $existing->provisioning_status);
        $this->assertNull($this->onAdmin(fn () => CommerceShopAccount::find($account->id)), 'vorgemerkte Zeile übernommen');
        $event = $this->onAdmin(fn () => $event->fresh());
        $this->assertSame('done', $event->status);
        $this->assertSame($existing->id, $event->shop_account_id);
        $this->assertArrayNotHasKey('email', $event->payload, 'personenbezogene Daten nach Erledigung entfernt');
        $this->assertTrue($this->onAdmin(fn () => $existing->canPurchase()));
    }

    public function test_provisioning_never_moves_a_shop_account_that_buys_for_another_school(): void
    {
        [$tenant, , $account, $event] = $this->pendingProvisioning();
        $other = $this->createTestTenant('Andere Schule');
        $otherAdmin = $this->createTenantUser($other, 'admin');
        $elsewhere = $this->onAdmin(fn () => CommerceShopAccount::create([
            'shop_id' => $this->shopId, 'shop_customer_id' => '4905', 'user_id' => $otherAdmin->id, 'tenant_id' => $other->id,
            'status' => 'linked', 'email' => 'anders@example.test', 'linked_at' => now(),
        ]));
        Http::fake(fn () => Http::response(['customer_id' => 4905, 'created' => false, 'matched_by' => 'app_user'], 200));

        $this->assertTrue($this->onAdmin(fn () => app(Outbox::class)->processOne($event->id)));

        $account = $this->onAdmin(fn () => $account->fresh());
        $this->assertSame('needs_link', $account->provisioning_status);
        $this->assertStringContainsString('andere Bootsschule', $account->provisioning_error);
        $this->assertNull($account->shop_customer_id);
        $this->assertSame($tenant->id, $account->tenant_id);
        $elsewhere = $this->onAdmin(fn () => $elsewhere->fresh());
        $this->assertSame($other->id, $elsewhere->tenant_id, 'nicht umgehängt');
        $this->assertSame($otherAdmin->id, $elsewhere->user_id);
        $this->assertSame('done', $this->onAdmin(fn () => $event->fresh()->status));

        $superadmin = $this->createSuperAdmin();
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'all']))->assertOk()
            ->assertSee('kauft bereits für eine andere Bootsschule ein');
        // Die Schule sieht im Codes-Tab, dass sie selbst verknüpfen muss.
        $owner = $this->onAdmin(fn () => User::find($account->user_id));
        $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=coupons')->assertOk()->assertSee('Shop verknüpfen');
    }

    public function test_needs_link_when_the_shop_has_a_foreign_account_with_the_same_email(): void
    {
        [, , $account, $event] = $this->pendingProvisioning();
        Http::fake(fn () => Http::response(['customer_id' => 4906, 'created' => false, 'matched_by' => 'email'], 200));

        $this->assertTrue($this->onAdmin(fn () => app(Outbox::class)->processOne($event->id)));

        $account = $this->onAdmin(fn () => $account->fresh());
        $this->assertSame('needs_link', $account->provisioning_status);
        $this->assertNull($account->provisioning_error);
        $this->assertNull($account->shop_customer_id, 'E-Mail-Gleichheit verknüpft nie');
        $event = $this->onAdmin(fn () => $event->fresh());
        $this->assertSame('done', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertArrayNotHasKey('email', $event->payload);
        // Erledigt: kein zweiter Versand.
        $this->assertFalse($this->onAdmin(fn () => app(Outbox::class)->processOne($event->id)));
        Http::assertSentCount(1);
    }

    public function test_superadmin_sees_the_outbox_state_and_can_filter_running_provisionings(): void
    {
        [, , $account, $event] = $this->pendingProvisioning();
        $superadmin = $this->createSuperAdmin();
        $this->onAdmin(fn () => CommerceEvent::whereKey($event->id)->update(['status' => 'sending', 'claimed_at' => now()]));
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'provisioning']))->assertOk()
            ->assertSee($account->email)->assertSee('wird gerade an den Shop gesendet');

        $this->onAdmin(fn () => CommerceEvent::whereKey($event->id)->update(['status' => 'pending', 'claimed_at' => null, 'attempts' => 1,
            'available_at' => now()->addMinutes(5), 'last_error' => 'Shop antwortete 503']));
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'provisioning']))->assertOk()
            ->assertSee('1. Versuch fehlgeschlagen')->assertSee('Shop antwortete 503');
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'linked']))->assertOk()->assertSee($account->email);
        $this->actingAs($superadmin)->get(route('superadmin.shop-accounts.index', ['status' => 'rejected']))->assertOk()->assertDontSee($account->email);
    }
}
