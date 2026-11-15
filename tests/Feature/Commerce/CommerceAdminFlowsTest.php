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
        // Die Registrierung legt kein App-Konto an (unbestätigte Adresse).
        $this->assertNull($account->user_id);

        return $account;
    }

    /** Nach der Freigabe: das dabei angelegte App-Konto aufräumen. */
    private function freshAccount(CommerceShopAccount $account): CommerceShopAccount
    {
        $account = $this->onAdmin(fn () => $account->fresh());
        if ($account->user_id && ! in_array($account->user_id, $this->createdUserIds, true)) {
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

        $account = $this->freshAccount($account);
        $tenant = $this->onAdmin(fn () => Tenant::where('slug', $slug)->firstOrFail());
        $this->createdTenantIds[] = $tenant->id;
        // Das App-Konto entsteht erst jetzt, aus den Angaben des Shopkontos.
        $user = $this->onAdmin(fn () => User::find($account->user_id));
        $this->assertSame([$account->email, 'invited', 'Kim Meer', 'Kim', 'Meer'], [$user->email, $user->status, $user->display_name, $user->first_name, $user->last_name]);
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
        $account = $this->freshAccount($account);
        $this->assertNotNull($account->user_id);
        $this->assertSame('admin', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $account->user_id)->value('role')));

        // Bereits geprüft: kein zweites Freigeben.
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'existing', 'tenant_id' => $tenant->id])->assertStatus(409);

        $other = $this->register('803', 'dritt-'.Str::lower(Str::random(6)).'@example.test');
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $other), ['note' => 'keine Bootsschule'])->assertRedirect();
        $this->assertSame('rejected', $this->onAdmin(fn () => $other->fresh()->status));
        $this->assertFalse($this->onAdmin(fn () => User::where('email', $other->email)->exists()), 'Ablehnung hinterlässt kein App-Konto');
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

        // Neu registrierte Adresse, die inzwischen ein App-Konto hat (selbst registriert
        // oder von der Schule als Lernende eingetragen): keine Freigabe über die
        // E-Mail, sondern Warten auf den Verknüpfungscode -- und keine Hochstufung.
        $account = $this->register('812', 'neu-'.Str::lower(Str::random(6)).'@example.test');
        $meanwhile = $this->createTenantUser($tenant, 'learner', ['email' => $account->email]);
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $account), ['mode' => 'existing', 'tenant_id' => $tenant->id])
            ->assertRedirect()->assertSessionHasErrors('mode');
        $this->assertSame('learner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $meanwhile->id)->value('role')));
        $account = $this->onAdmin(fn () => $account->fresh());
        $this->assertSame(['awaiting_link', null, null], [$account->status, $account->user_id, $account->tenant_id]);
        // Verknüpfen kann sie es dann nur per Code (hier: ein Admin der Schule).
        $schoolAdmin = $this->createTenantUser($tenant, 'admin');
        $this->actingAsInTenant($schoolAdmin, $tenant)->post('/admin/shop-link-code');
        $uri = '/api/commerce/v1/accounts/812/link';
        $body = json_encode(['link_code' => session('shopLinkCode')]);
        $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(Signature::headers('shop', 'test-secret-123', 'POST', $uri, $body)
            + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)->assertOk()->assertJsonPath('data.status', 'linked');

        // Ältere Registrierung, die noch ein App-Konto mitbrachte und deren Person die
        // Schule inzwischen als Lernende eingetragen hat: keine stille Hochstufung zur Admin.
        $legacyUser = $this->onAdmin(fn () => User::create(['display_name' => 'Alt Konto', 'email' => 'alt-'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt(Str::random(20)), 'status' => 'invited']));
        $this->createdUserIds[] = $legacyUser->id;
        $legacy = $this->onAdmin(fn () => CommerceShopAccount::create(['shop_id' => $this->shopId, 'shop_customer_id' => '814', 'user_id' => $legacyUser->id,
            'status' => 'pending_review', 'email' => $legacyUser->email]));
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $legacyUser->id, 'role' => 'learner', 'status' => 'active']));
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.approve', $legacy), ['mode' => 'existing', 'tenant_id' => $tenant->id])
            ->assertSessionHasErrors('tenant_id');
        $this->assertSame('learner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $legacyUser->id)->value('role')));
        $this->assertSame('pending_review', $this->onAdmin(fn () => $legacy->fresh()->status));

        // Ablehnen entfernt das nie benutzte App-Konto einer älteren Registrierung
        // (die Adresse ist danach wieder frei) -- beim Konto oben nicht, weil die
        // Person schon Lernende der Schule ist.
        $unusedUser = $this->onAdmin(fn () => User::create(['display_name' => 'Frei', 'email' => 'frei-'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt(Str::random(20)), 'status' => 'invited']));
        $this->createdUserIds[] = $unusedUser->id;
        $unused = $this->onAdmin(fn () => CommerceShopAccount::create(['shop_id' => $this->shopId, 'shop_customer_id' => '813', 'user_id' => $unusedUser->id,
            'status' => 'pending_review', 'email' => $unusedUser->email]));
        $this->actingAs($superadmin)->post(route('superadmin.shop-accounts.reject', $unused))->assertRedirect();
        $this->assertNull($this->onAdmin(fn () => User::find($unusedUser->id)), 'unbenutztes App-Konto entfernt');
        $this->assertNull($this->onAdmin(fn () => $unused->fresh()->user_id));
        $account = $legacy;

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
}
