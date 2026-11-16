<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\WebSessionController;
use App\Models\ApiMobileWebSession;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Services\PersonalDataExportService;
use App\Support\CanonicalUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mobile-API: Einmal-Anmeldung der eingebetteten Webansicht (POST /auth/web-session). */
class WebSessionApiTest extends MobileApiTestCase
{
    /** Schulen unter CENTRAL_DOMAIN/<slug> haben Kennungen in Kleinbuchstaben (siehe PathTenancyTest). */
    protected function createTestTenant(string $namePrefix = 'Test Schule'): Tenant
    {
        $tenant = parent::createTestTenant($namePrefix);
        $tenant->slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $tenant->slug]));

        return $tenant;
    }

    private function createWebSession(string $token, Tenant $tenant, string $redirect = '/dashboard'): array
    {
        return $this->api('POST', '/auth/web-session', $token, $tenant, ['redirect' => $redirect])->assertCreated()->json('data');
    }

    /** Die Adresse wie die WebView aufrufen (mit Verifier-Header), ohne vorhandene Web-Anmeldung. */
    private function open(string $url, ?string $verifier)
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $response = $this->get($url, $verifier === null ? [] : [WebSessionController::VERIFIER_HEADER => $verifier]);
        // Testclient: relative Adressen danach wieder auf der zentralen Domain (nicht unter /<slug>).
        $this->app['url']->setRequest(Request::create(CanonicalUrl::root().'/'));

        return $response;
    }

    public function test_the_one_time_url_logs_the_web_view_in_once_and_drops_the_code(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        $data = $this->createWebSession($token, $tenant, '/courses?tab=alle');
        $root = CanonicalUrl::root($tenant);
        $this->assertSame($root.'/courses?tab=alle', $data['redirect']);
        $this->assertStringStartsWith($root.'/courses?tab=alle&'.WebSessionController::CODE_PARAM.'=bsw_', $data['url']);
        $this->assertSame(60, $data['expires_in']);
        // Nur Hashes gespeichert, an Schule und Gerätesitzung gebunden.
        parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $query);
        $row = $this->onAdmin(fn () => ApiMobileWebSession::withoutGlobalScopes()->where('user_id', $user->id)->sole());
        $this->assertSame(hash('sha256', $query[WebSessionController::CODE_PARAM]), $row->code_hash);
        $this->assertSame(hash('sha256', $data['verifier']), $row->verifier_hash);
        $this->assertSame($tenant->id, $row->tenant_id);

        $this->open($data['url'], $data['verifier'])
            ->assertRedirect($root.'/courses?tab=alle')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertAuthenticatedAs($user, 'web');

        // Zweiter Aufruf derselben Adresse: keine Anmeldung mehr.
        $this->open($data['url'], $data['verifier'])->assertRedirect($root.'/courses?tab=alle');
        $this->assertGuest('web');
    }

    public function test_without_the_right_verifier_the_code_is_burned_without_login(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $data = $this->createWebSession($token, $tenant);

        $this->open($data['url'], null)->assertRedirect(CanonicalUrl::root($tenant).'/dashboard');
        $this->assertGuest('web');
        // Einmalig: auch mit dem richtigen Verifier danach nicht mehr.
        $this->open($data['url'], $data['verifier']);
        $this->assertGuest('web');

        $other = $this->createWebSession($token, $tenant);
        $this->open($other['url'], 'bsv_'.str_repeat('A', 43));
        $this->assertGuest('web');
    }

    public function test_the_code_expires_after_sixty_seconds(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $data = $this->createWebSession($this->apiLogin($user), $tenant);

        $this->travel(61)->seconds();
        $this->open($data['url'], $data['verifier']);
        $this->assertGuest('web');
    }

    public function test_the_code_only_works_at_the_school_it_was_issued_for(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $data = $this->createWebSession($this->apiLogin($user), $tenant);

        $foreign = str_replace(CanonicalUrl::root($tenant), CanonicalUrl::root($other), $data['url']);
        $this->open($foreign, $data['verifier'])->assertRedirect(CanonicalUrl::root($other).'/dashboard');
        $this->assertGuest('web');
        // Bei der richtigen Schule ist der Code noch unverbraucht.
        $this->open($data['url'], $data['verifier']);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_logged_out_device_session_or_lost_membership_invalidates_the_code(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $data = $this->createWebSession($token, $tenant);

        $this->api('POST', '/auth/logout', $token)->assertNoContent();
        $this->open($data['url'], $data['verifier']);
        $this->assertGuest('web');

        $token = $this->apiLogin($user);
        $fresh = $this->createWebSession($token, $tenant);
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['status' => 'suspended']));
        $this->open($fresh['url'], $fresh['verifier']);
        $this->assertGuest('web');
    }

    public function test_only_learning_pages_of_this_school_are_allowed_as_target(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $root = CanonicalUrl::root($tenant);

        foreach ([
            'https://evil.example/courses', '//evil.example/courses', '/\\evil.example', 'javascript:alert(1)',
            '/profile', '/admin', '/logout', '/courses/../profile', '/courses/%2e%2e/profile', '/dashboard#x',
            $root.'@evil.example/dashboard', CanonicalUrl::root($other).'/dashboard', 'courses', '',
        ] as $target) {
            $this->api('POST', '/auth/web-session', $token, $tenant, ['redirect' => $target])
                ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        }

        $this->assertSame($root.'/dashboard', $this->createWebSession($token, $tenant, $root.'/dashboard')['redirect']);
        $this->assertSame($root.'/courses/abc', $this->createWebSession($token, $tenant, '/courses/abc')['redirect']);
    }

    public function test_team_accounts_and_disabled_setting_get_no_web_session(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        // Ausbilder bei einer anderen Schule: keine Web-Anmeldung über die App.
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'instructor', 'status' => 'active']));
        $token = $this->apiLogin($user);
        $this->api('POST', '/auth/web-session', $token, $tenant, ['redirect' => '/dashboard'])
            ->assertStatus(403)->assertJsonPath('code', 'WEB_SESSION_NOT_ALLOWED');

        $learner = $this->createTenantUser($tenant, 'learner');
        config(['mobile_api.web_session_enabled' => false]);
        $this->api('POST', '/auth/web-session', $learner, $tenant, ['redirect' => '/dashboard'])
            ->assertStatus(403)->assertJsonPath('code', 'WEB_SESSION_DISABLED');
    }

    public function test_creation_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        for ($i = 0; $i < 20; $i++) {
            $this->createWebSession($token, $tenant);
        }
        $this->api('POST', '/auth/web-session', $token, $tenant, ['redirect' => '/dashboard'])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_codes_are_exported_erased_with_the_membership_and_pruned(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $token = $this->apiLogin($user);
        $this->createWebSession($token, $tenant);
        $this->createWebSession($token, $other);

        $export = $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export($user, $tenant));
        $this->assertCount(1, $export['app_web_logins']);
        $this->assertSame('/dashboard', $export['app_web_logins'][0]['redirect_path']);
        $this->assertArrayNotHasKey('app_web_logins', $this->asTenant($tenant, fn () => app(PersonalDataExportService::class)->export($user, $tenant, forSchool: true)));

        // Schule verlassen: deren Codes verschwinden, die der anderen Schule bleiben.
        $this->asTenant($tenant, fn () => app(AccountErasureService::class)->leaveTenant($user, $tenant));
        $this->onAdmin(function () use ($user, $tenant, $other) {
            $this->assertFalse(ApiMobileWebSession::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists());
            $this->assertTrue(ApiMobileWebSession::withoutGlobalScopes()->where('tenant_id', $other->id)->where('user_id', $user->id)->exists());
        });

        // Nach einem Tag räumt der stündliche Lauf die Codes ab.
        $this->travel(2)->days();
        $this->artisan('app:prune-personal-data', ['--mobile-api-only' => true])->assertSuccessful();
        $this->onAdmin(fn () => $this->assertFalse(ApiMobileWebSession::withoutGlobalScopes()->where('user_id', $user->id)->exists()));
        $this->assertNotNull($this->onAdmin(fn () => User::find($user->id)));
    }
}
