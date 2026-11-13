<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/** Mobile-API: Anmeldung, Gerätesitzungen, 2FA, Passwort, Profil. */
class AuthAndAccountApiTest extends MobileApiTestCase
{
    public function test_login_returns_tokens_profile_and_only_learner_memberships(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        // Als Ausbilder in einer zweiten Schule: dort nicht in der App.
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'instructor', 'status' => 'active']));

        $response = $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => 'password', 'device_name' => 'iPhone']);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonCount(1, 'data.memberships')
            ->assertJsonPath('data.memberships.0.tenant_slug', $tenant->slug)
            ->assertJsonPath('data.memberships.0.role', 'learner');
        $this->assertStringStartsWith('bsa_', $response->json('data.access_token'));
        $this->onAdmin(fn () => $this->assertFalse(DB::table('api_device_session')->where('access_token_hash', $response->json('data.access_token'))->exists(), 'nur Hash gespeichert'));
    }

    public function test_wrong_password_gives_a_problem_response(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'falsch', 'device_name' => 'x'])
            ->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'UNAUTHENTICATED')->assertJsonStructure(['request_id']);
    }

    public function test_refresh_rotates_and_reuse_revokes_the_session(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data');

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json('data');
        $this->assertNotSame($login['access_token'], $refreshed['access_token']);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$login['access_token']])->assertStatus(401);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$refreshed['access_token']])->assertOk();

        // Gleich noch einmal (Antwort verloren): neues Paar statt Abmeldung.
        $retried = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json('data');
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$retried['access_token']])->assertOk();

        // Nach Ablauf der Toleranz: Sitzung wird beendet.
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertStatus(401);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$retried['access_token']])->assertStatus(401);
    }

    public function test_two_factor_login_needs_the_code(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $secret = Totp::generateSecret();
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save());

        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.access_token');
        $challenge = $first->json('data.challenge_token');

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'TWO_FACTOR_INVALID');
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'session_id']]);
    }

    public function test_sessions_can_be_listed_and_revoked_and_logout_ends_the_current_one(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $a = $this->apiLogin($user);
        $b = $this->apiLogin($user);

        $list = $this->getJson('/api/v1/me/sessions', ['Authorization' => 'Bearer '.$a])->assertOk()->assertJsonCount(2, 'data');
        $otherId = collect($list->json('data'))->firstWhere('current', false)['id'];

        $this->deleteJson('/api/v1/me/sessions/'.$otherId, [], ['Authorization' => 'Bearer '.$a])->assertNoContent();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$b])->assertStatus(401);
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$a])->assertNoContent();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$a])->assertStatus(401);
    }

    public function test_password_reset_via_api_revokes_app_sessions(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $resetToken = $this->onAdmin(fn () => Password::broker()->createToken(User::find($user->id)));

        $this->postJson('/api/v1/auth/password/reset', ['email' => $user->email, 'token' => 'falsch-falsch-falsch-falsch', 'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123'])
            ->assertStatus(422)->assertJsonPath('code', 'PASSWORD_RESET_INVALID_OR_EXPIRED');
        $this->postJson('/api/v1/auth/password/reset', ['email' => $user->email, 'token' => $resetToken, 'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123'])
            ->assertNoContent();

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'niemand@example.test'])->assertStatus(202);
    }

    public function test_tenant_endpoints_check_membership_verification_and_suspension(): void
    {
        $tenant = $this->createTestTenant();
        $foreign = $this->createTestTenant('Fremde Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $headers = fn ($slug) => ['Authorization' => 'Bearer '.$token, 'X-Tenant-Slug' => $slug];

        $this->getJson('/api/v1/courses', $headers($tenant->slug))->assertOk()->assertJsonPath('meta.tenant.slug', $tenant->slug);
        $this->getJson('/api/v1/courses', $headers($foreign->slug))->assertStatus(403)->assertJsonPath('code', 'TENANT_MEMBERSHIP_REQUIRED');
        $this->getJson('/api/v1/courses', $headers('gibt-es-nicht'))->assertStatus(403)->assertJsonPath('code', 'TENANT_MEMBERSHIP_REQUIRED');
        $this->getJson('/api/v1/courses', ['Authorization' => 'Bearer '.$token])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->onAdmin(fn () => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['status' => 'suspended']));
        $this->getJson('/api/v1/courses', $headers($tenant->slug))->assertStatus(403)->assertJsonPath('code', 'TENANT_MEMBERSHIP_SUSPENDED');

        $this->onAdmin(fn () => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['status' => 'active']));
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['email_verified_at' => null]));
        $this->getJson('/api/v1/courses', $headers($tenant->slug))->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
        $this->getJson('/api/v1/me', $headers($tenant->slug))->assertOk()->assertJsonPath('data.user.email_verified', false);
    }

    public function test_a_suspended_account_loses_api_access_immediately(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['status' => 'suspended']));

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
