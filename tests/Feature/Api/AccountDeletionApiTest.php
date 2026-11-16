<?php

namespace Tests\Feature\Api;

use App\Models\ApiAccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Favorite;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AccountDeletionConfirmation;
use App\Services\AccountErasureService;
use App\Services\AppAccountDeletion;
use App\Services\PersonalDataExportService;
use App\Support\CanonicalUrl;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/** Mobile-API: Kontolöschung aus der App (wie „Konto löschen“ im Web-Profil). */
class AccountDeletionApiTest extends MobileApiTestCase
{
    /** Löschung beantragen und die Werte aus dem Link der Bestätigungsmail liefern. */
    private function requestDeletion(User $user, string $token, Tenant $tenant, bool $already = false): array
    {
        Notification::fake();
        $response = $this->api('POST', '/me/deletion-request', $token, $tenant)->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.already_requested', $already);

        $url = null;
        Notification::assertSentTo($user, AccountDeletionConfirmation::class, function (AccountDeletionConfirmation $n) use (&$url) {
            $url = $n->url();

            return true;
        });
        // Link auf der zentralen Domain (nie auf dem Host der Anfrage).
        $this->assertStringStartsWith(CanonicalUrl::root().'/app/konto-loeschen?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($response->json('data.request_id'), $query['request_id']);

        return $query;
    }

    private function confirm(array $values)
    {
        return $this->postJson('/api/v1/me/deletion-request/confirm', $values);
    }

    public function test_confirmed_deletion_erases_the_only_membership_and_the_whole_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $question = $this->anyPublishedQuestionIn($this->existingModule('SBF_BASIS'));
        $this->onAdmin(function () use ($tenant, $user, $question) {
            Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $question[0]->id]);
            AuditLog::record($tenant->id, $user->id, 'profile.update', 'app_user', $user->id, null, ['email' => $user->email]);
        });

        $values = $this->requestDeletion($user, $token, $tenant);
        // Nur der Hash des Schlüssels liegt in der Datenbank, an die Schule gebunden.
        $row = $this->onAdmin(fn () => ApiAccountDeletionRequest::withoutGlobalScopes()->findOrFail($values['request_id']));
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertSame(hash('sha256', $values['confirm_key']), $row->confirm_key_hash);
        // Bis zur Bestätigung wird nichts gelöscht.
        $this->api('GET', '/me', $token)->assertOk();

        $this->confirm($values)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.account_deleted', true);

        $this->onAdmin(function () use ($user, $tenant, $values) {
            $this->assertNull(User::find($user->id));
            $this->assertFalse(TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->exists());
            $this->assertFalse(Favorite::withoutGlobalScopes()->where('user_id', $user->id)->exists());
            $this->assertFalse(DB::table('api_device_session')->where('user_id', $user->id)->exists());
            $this->assertFalse(ApiAccountDeletionRequest::withoutGlobalScopes()->whereKey($values['request_id'])->exists());
            // Protokoll: Personendaten entfernt, Nachweis der Löschung ohne Personenbezug.
            $this->assertSame(0, AuditLog::where('tenant_id', $tenant->id)->where('action', 'profile.update')->whereNotNull('after_data')->count());
            $this->assertTrue(AuditLog::where('tenant_id', $tenant->id)->where('action', 'profile.delete')->whereNull('actor_user_id')->exists());
        });
        // Alle App-Sitzungen sind beendet.
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        // Der Link ist verbraucht.
        $this->confirm($values)->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
    }

    public function test_with_another_school_only_this_membership_is_erased(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $token = $this->apiLogin($user);

        $values = $this->requestDeletion($user, $token, $tenant);
        $this->confirm($values)->assertOk()->assertJsonPath('data.account_deleted', false);

        $this->onAdmin(function () use ($user, $tenant, $other) {
            $this->assertNotNull(User::find($user->id));
            $this->assertFalse(TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists());
            $this->assertTrue(TenantUser::withoutGlobalScopes()->where('tenant_id', $other->id)->where('user_id', $user->id)->exists());
        });
        // Die App bleibt für die andere Schule angemeldet (Sitzungen gelten kontoweit, wie im Web).
        $this->api('GET', '/courses', $token, $other)->assertOk();
        $this->api('GET', '/courses', $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'TENANT_MEMBERSHIP_REQUIRED');
    }

    public function test_a_failed_deletion_keeps_the_link_valid(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $values = $this->requestDeletion($user, $token, $tenant);

        // Die Löschung scheitert unterwegs (z. B. Datenbankfehler).
        $this->app->instance(AccountErasureService::class, new class(app(TenantContext::class)) extends AccountErasureService
        {
            public function leaveTenant(User $user, Tenant $tenant, bool $forgetOtherSchoolsInvitations = true): void
            {
                throw new RuntimeException('Löschung fehlgeschlagen (Test)');
            }
        });
        $this->confirm($values)->assertStatus(500);

        // Antrag und Konto sind unverändert da, derselbe Link funktioniert danach.
        $this->onAdmin(function () use ($user, $values) {
            $this->assertTrue(ApiAccountDeletionRequest::withoutGlobalScopes()->whereKey($values['request_id'])->exists());
            $this->assertNotNull(User::find($user->id));
        });
        $this->app->forgetInstance(AccountErasureService::class);
        $this->app->forgetInstance(AppAccountDeletion::class);
        $this->confirm($values)->assertOk()->assertJsonPath('data.account_deleted', true);
        $this->onAdmin(fn () => $this->assertNull(User::find($user->id)));
    }

    public function test_a_repeated_request_sends_a_new_link_and_invalidates_the_old_one(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        $first = $this->requestDeletion($user, $token, $tenant);
        $second = $this->requestDeletion($user, $token, $tenant, already: true);
        $this->assertSame($first['request_id'], $second['request_id']);

        $this->confirm($first)->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
        $this->onAdmin(fn () => $this->assertNotNull(User::find($user->id)));
        $this->confirm($second)->assertOk();
    }

    public function test_invalid_expired_or_foreign_links_delete_nothing(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $values = $this->requestDeletion($user, $token, $tenant);

        $this->confirm(['request_id' => $values['request_id'], 'confirm_key' => 'bsd_'.Str::random(48)])
            ->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
        $this->confirm(['request_id' => (string) Str::uuid(), 'confirm_key' => $values['confirm_key']])
            ->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
        $this->confirm(['request_id' => 'kein-uuid', 'confirm_key' => 'x'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // Inzwischen Ausbilder geworden: Löschung nur noch im Web (u. a. Prüfung „einziger Inhaber“).
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['role' => 'instructor']));
        $this->confirm($values)->assertStatus(422);
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['role' => 'learner']));

        // Nach Ablauf (7 Tage) ungültig.
        $this->travel(8)->days();
        $this->confirm($values)->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
        $this->onAdmin(fn () => $this->assertNotNull(User::find($user->id)));
    }

    public function test_confirmation_attempts_are_rate_limited(): void
    {
        $values = ['request_id' => (string) Str::uuid(), 'confirm_key' => 'falsch'];
        for ($i = 0; $i < 10; $i++) {
            $this->confirm($values)->assertStatus(422);
        }
        $this->confirm($values)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_platform_accounts_cannot_delete_themselves_in_the_app(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);

        $this->api('POST', '/me/deletion-request', $user, $tenant)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DELETION_NOT_ALLOWED');
    }

    public function test_open_requests_are_exported_and_pruned_after_expiry(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $values = $this->requestDeletion($user, $token, $tenant);

        $export = $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export(User::find($user->id) ?? $user, $tenant));
        $this->assertCount(1, $export['app_deletion_requests']);

        $this->travel(8)->days();
        $this->artisan('app:prune-personal-data', ['--mobile-api-only' => true])->assertSuccessful();
        $this->onAdmin(fn () => $this->assertFalse(ApiAccountDeletionRequest::withoutGlobalScopes()->whereKey($values['request_id'])->exists()));
    }
}
