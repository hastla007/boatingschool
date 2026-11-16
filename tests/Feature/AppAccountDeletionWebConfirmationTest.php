<?php

namespace Tests\Feature;

use App\Models\ApiAccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Favorite;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AccountDeletionConfirmation;
use App\Services\AccountSetupLink;
use App\Support\CanonicalUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\MobileApiTestCase;

/**
 * Web-Bestätigung der Kontolöschung aus der App: Ziel des Mail-Links, wenn
 * die App nicht installiert ist (vorher 404, es wurde nichts gelöscht).
 */
class AppAccountDeletionWebConfirmationTest extends MobileApiTestCase
{
    private const INVALID = 'Dieser Bestätigungslink ist ungültig oder abgelaufen.';

    /** Löschantrag wie aus der App, mit bekanntem Schlüssel. */
    private function pendingRequest(User $user, Tenant $tenant): array
    {
        $key = 'bsd_'.Str::random(48);
        $row = $this->onAdmin(fn () => ApiAccountDeletionRequest::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'confirm_key_hash' => hash('sha256', $key),
            'requested_at' => now(),
            'expires_at' => now()->addDays(7),
        ]));

        return ['request_id' => (string) $row->id, 'confirm_key' => $key];
    }

    private function show(array $values): TestResponse
    {
        return $this->get('/app/konto-loeschen?'.http_build_query($values));
    }

    private function confirmWeb(array $values): TestResponse
    {
        return $this->post('/app/konto-loeschen', $values);
    }

    private function assertInvalid(TestResponse $response): void
    {
        $response->assertNotFound()->assertSee('Link ungültig')->assertSee(self::INVALID)
            ->assertDontSee('Endgültig löschen');
    }

    private function userExists(User $user): bool
    {
        return $this->onAdmin(fn () => User::whereKey($user->id)->exists());
    }

    public function test_the_mail_link_opens_a_confirmation_page_that_deletes_nothing(): void
    {
        $tenant = $this->createTestTenant('Segelschule Nord');
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        Notification::fake();
        $this->api('POST', '/me/deletion-request', $token, $tenant)->assertStatus(202);
        $url = null;
        Notification::assertSentTo($user, AccountDeletionConfirmation::class, function (AccountDeletionConfirmation $n) use (&$url) {
            $url = $n->url();

            return true;
        });

        // Genau der Link aus der Mail (zentrale Domain) zeigt die Seite.
        $response = $this->get((string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY));
        $response->assertOk()
            ->assertSee($tenant->name)
            ->assertSee('Endgültig löschen')
            ->assertSee('dein Konto selbst')
            ->assertSee('Dieser Link ist bis', false)
            ->assertDontSee('mit einem anderen Konto angemeldet')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // Formular bestätigt per POST an denselben Pfad.
        $response->assertSee('method="POST"', false)->assertSee('name="confirm_key"', false);

        // Mehrfaches Öffnen (z. B. Link-Vorschau im Mailprogramm) löscht nichts.
        $this->get((string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk();
        $this->assertTrue($this->userExists($user));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $values);
        $this->onAdmin(fn () => $this->assertTrue(ApiAccountDeletionRequest::withoutGlobalScopes()->whereKey($values['request_id'])->exists()));
        $this->api('GET', '/me', $token)->assertOk();
    }

    public function test_confirming_erases_the_only_membership_and_the_whole_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $question = $this->anyPublishedQuestionIn($this->existingModule('SBF_BASIS'));
        $this->onAdmin(fn () => Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $question[0]->id]));
        $values = $this->pendingRequest($user, $tenant);

        $this->confirmWeb($values)->assertOk()
            ->assertSee('Dein Konto wurde gelöscht')
            ->assertDontSee('Endgültig löschen');

        $this->onAdmin(function () use ($user, $tenant, $values) {
            $this->assertNull(User::find($user->id));
            $this->assertFalse(TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->exists());
            $this->assertFalse(Favorite::withoutGlobalScopes()->where('user_id', $user->id)->exists());
            $this->assertFalse(ApiAccountDeletionRequest::withoutGlobalScopes()->whereKey($values['request_id'])->exists());
            $audit = AuditLog::where('tenant_id', $tenant->id)->where('action', 'profile.delete')->first();
            $this->assertNotNull($audit);
            $this->assertNull($audit->actor_user_id);
            $this->assertSame('web', $audit->after_data['channel'] ?? null);
        });
        // Auch die App-Sitzungen sind beendet.
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }

    public function test_with_another_school_only_this_membership_is_erased(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $values = $this->pendingRequest($user, $tenant);

        $this->show($values)->assertOk()->assertSee('Dein Konto bleibt bestehen')->assertDontSee('dein Konto selbst');
        $this->confirmWeb($values)->assertOk()->assertSee('Dein Konto bei '.$tenant->name.' wurde gelöscht');

        $this->onAdmin(function () use ($user, $tenant, $other) {
            $this->assertNotNull(User::find($user->id));
            $this->assertFalse(TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists());
            $this->assertTrue(TenantUser::withoutGlobalScopes()->where('tenant_id', $other->id)->where('user_id', $user->id)->exists());
        });
    }

    public function test_used_expired_and_wrong_links_get_the_same_message(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->createTenantUser($tenant, 'learner');
        $values = $this->pendingRequest($user, $tenant);

        // Falscher Schlüssel, unbekannter Antrag, unbrauchbare Werte.
        $wrongKey = ['request_id' => $values['request_id'], 'confirm_key' => 'bsd_'.Str::random(48)];
        $this->assertInvalid($this->show($wrongKey));
        $this->assertInvalid($this->confirmWeb($wrongKey));
        $this->assertInvalid($this->show(['request_id' => (string) Str::uuid(), 'confirm_key' => $values['confirm_key']]));
        $this->assertInvalid($this->show(['request_id' => 'kein-uuid', 'confirm_key' => 'x']));
        $this->assertInvalid($this->get('/app/konto-loeschen'));
        $this->assertInvalid($this->confirmWeb([]));
        $this->assertTrue($this->userExists($user));

        // Abgelaufen.
        $this->travel(8)->days();
        $this->assertInvalid($this->show($values));
        $this->assertInvalid($this->confirmWeb($values));
        $this->assertTrue($this->userExists($user));
        $this->travelBack();

        // Benutzt (hier: zweite Mitgliedschaft, damit das Konto bleibt und erneut beantragt werden könnte).
        $second = $this->createTestTenant('Zweite Schule');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $second->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $this->confirmWeb($values)->assertOk();
        $this->assertInvalid($this->show($values));
        $this->assertInvalid($this->confirmWeb($values));
        // Der API-Endpunkt lehnt denselben benutzten Link ebenso ab.
        $this->postJson('/api/v1/me/deletion-request/confirm', $values)->assertStatus(422)->assertJsonPath('code', 'DELETION_CONFIRMATION_INVALID');
    }

    public function test_confirming_requires_a_valid_csrf_token(): void
    {
        // Laravel lässt die CSRF-Prüfung in Unit-Tests sonst aus.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $values = $this->pendingRequest($user, $tenant);

        $this->confirmWeb($values)->assertStatus(419);
        $this->confirmWeb($values + ['_token' => 'falsch'])->assertStatus(419);
        $this->assertTrue($this->userExists($user));

        $this->withSession(['_token' => 'richtig'])->confirmWeb($values + ['_token' => 'richtig'])->assertOk();
        $this->assertFalse($this->userExists($user));
    }

    public function test_platform_and_team_accounts_are_rejected(): void
    {
        $tenant = $this->createTestTenant();

        $superadmin = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);
        $values = $this->pendingRequest($superadmin, $tenant);
        $this->assertInvalid($this->show($values));
        $this->assertInvalid($this->confirmWeb($values));
        $this->assertTrue($this->userExists($superadmin));

        $instructor = $this->createTenantUser($tenant, 'instructor');
        $values = $this->pendingRequest($instructor, $tenant);
        $this->assertInvalid($this->show($values));
        $this->assertInvalid($this->confirmWeb($values));
        $this->assertTrue($this->userExists($instructor));
        $this->onAdmin(fn () => $this->assertTrue(TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $instructor->id)->exists()));
    }

    public function test_a_different_signed_in_person_only_confirms_the_requested_account(): void
    {
        $tenant = $this->createTestTenant();
        $requester = $this->createTenantUser($tenant, 'learner');
        $signedIn = $this->createTenantUser($tenant, 'learner');
        $values = $this->pendingRequest($requester, $tenant);

        $this->actingAs($signedIn);
        $this->show($values)->assertOk()->assertSee('mit einem anderen Konto angemeldet');
        $this->confirmWeb($values)->assertOk()->assertSee('Dein Konto wurde gelöscht');

        $this->assertFalse($this->userExists($requester));
        $this->assertTrue($this->userExists($signedIn));
        $this->onAdmin(fn () => $this->assertTrue(TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $signedIn->id)->exists()));
        // Die Sitzung der anderen Person bleibt bestehen.
        $this->assertAuthenticatedAs($signedIn);
    }

    public function test_the_own_web_session_ends_when_the_whole_account_is_deleted(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $values = $this->pendingRequest($user, $tenant);

        $this->actingAs($user);
        $this->show($values)->assertOk()->assertDontSee('mit einem anderen Konto angemeldet');
        $this->confirmWeb($values)->assertOk();

        $this->assertFalse($this->userExists($user));
        $this->assertGuest();
    }

    public function test_the_page_exists_only_on_the_central_domain(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $values = $this->pendingRequest($user, $tenant);

        $this->get('/'.$tenant->slug.'/app/konto-loeschen?'.http_build_query($values))->assertNotFound()->assertDontSee('Endgültig löschen');
        $this->post('/'.$tenant->slug.'/app/konto-loeschen', $values)->assertNotFound();
        $this->get('http://'.$tenant->slug.'.localhost/app/konto-loeschen?'.http_build_query($values))->assertNotFound()->assertDontSee('Endgültig löschen');
        $this->assertTrue($this->userExists($user));
    }

    public function test_attempts_are_rate_limited_per_link(): void
    {
        $values = ['request_id' => (string) Str::uuid(), 'confirm_key' => 'falsch'];
        for ($i = 0; $i < 10; $i++) {
            $this->show($values)->assertNotFound();
        }
        $this->show($values)->assertStatus(429)->assertHeader('Retry-After')->assertSee('Zu viele Versuche');
        $this->confirmWeb($values)->assertStatus(429);
        // Dasselbe Kontingent wie in der App.
        $this->postJson('/api/v1/me/deletion-request/confirm', $values)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_app_link_association_files_are_served_only_when_configured(): void
    {
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        config([
            'mobile_api.app_links.ios_app_ids' => 'ABCDE12345.de.example.app, ABCDE12345.de.example.beta',
            'mobile_api.app_links.android_package' => 'de.example.app',
            'mobile_api.app_links.android_sha256_fingerprints' => 'AA:BB:CC',
        ]);

        $this->get('/.well-known/apple-app-site-association')->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['applinks' => ['details' => [[
                'appIDs' => ['ABCDE12345.de.example.app', 'ABCDE12345.de.example.beta'],
                'components' => [
                    ['/' => '/app/konto-loeschen', 'comment' => 'Bestätigung der Kontolöschung'],
                    ['/' => '/reset-password/*', 'comment' => 'Passwort festlegen oder zurücksetzen'],
                    ['/' => '/*/reset-password/*', 'comment' => 'Passwort festlegen oder zurücksetzen (Pfad-Modus /<slug>/…)'],
                    ['/' => '/invitations/*/accept/*', 'comment' => 'Einladung annehmen'],
                    ['/' => '/*/invitations/*/accept/*', 'comment' => 'Einladung annehmen (Pfad-Modus /<slug>/…)'],
                    ['/' => '/verify-email/*/*', 'comment' => 'E-Mail-Adresse bestätigen'],
                    ['/' => '/*/verify-email/*/*', 'comment' => 'E-Mail-Adresse bestätigen (Pfad-Modus /<slug>/…)'],
                ],
            ]]]]);
        $this->get('/.well-known/assetlinks.json')->assertOk()->assertExactJson([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => 'de.example.app', 'sha256_cert_fingerprints' => ['AA:BB:CC']],
        ]]);

        // Nicht auf den Hosts der Schulen (Pfad-Modus).
        $tenant = $this->createTestTenant();
        $this->get('http://'.$tenant->slug.'.localhost/.well-known/apple-app-site-association')->assertNotFound();
        $this->get('http://'.$tenant->slug.'.localhost/.well-known/assetlinks.json')->assertNotFound();
    }

    public function test_the_default_app_link_paths_match_the_links_in_the_mails(): void
    {
        config(['mobile_api.app_links.ios_app_ids' => 'ABCDE12345.de.example.app']);
        $patterns = collect($this->get('/.well-known/apple-app-site-association')->assertOk()->json('applinks.details.0.components'))->pluck('/');
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        // Genau die Adressen, die die Mails erzeugen (CanonicalUrl, Pfad-Modus mit Slug).
        $resetPath = parse_url(app(AccountSetupLink::class)->url($user, $tenant), PHP_URL_PATH);
        $verifyPath = parse_url(CanonicalUrl::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)], $tenant), PHP_URL_PATH);
        $invitePath = parse_url(CanonicalUrl::temporarySignedRoute('invitations.accept', now()->addDay(), ['invitation' => (string) Str::uuid(), 'hash' => sha1($user->email)], $tenant), PHP_URL_PATH);
        $this->onAdmin(fn () => DB::table('account_setup_tokens')->where('email', $user->email)->delete());

        foreach ([$resetPath, $verifyPath, $invitePath, '/app/konto-loeschen'] as $path) {
            $this->assertTrue($patterns->contains(fn ($pattern) => $this->appleMatches($pattern, $path)), $path.' fehlt in der Zuordnungsdatei');
        }
        // Andere Seiten öffnen weiter im Browser.
        foreach (['/login', '/'.$tenant->slug.'/dashboard', '/'.$tenant->slug.'/app/konto-loeschen'] as $path) {
            $this->assertFalse($patterns->contains(fn ($pattern) => $this->appleMatches($pattern, $path)), $path.' darf nicht in die App');
        }
    }

    public function test_the_app_link_paths_are_configurable(): void
    {
        config([
            'mobile_api.app_links.ios_app_ids' => 'ABCDE12345.de.example.app',
            'mobile_api.app_links.paths' => ' /app/konto-loeschen , reset-password/* ,',
        ]);

        $this->get('/.well-known/apple-app-site-association')->assertOk()
            ->assertExactJson(['applinks' => ['details' => [[
                'appIDs' => ['ABCDE12345.de.example.app'],
                'components' => [
                    ['/' => '/app/konto-loeschen', 'comment' => 'Bestätigung der Kontolöschung'],
                    ['/' => '/reset-password/*', 'comment' => 'Link aus einer Mail'],
                    ['/' => '/*/reset-password/*', 'comment' => 'Link aus einer Mail (Pfad-Modus /<slug>/…)'],
                ],
            ]]]]);
    }

    public function test_in_subdomain_mode_the_school_subdomains_answer_but_custom_domains_never(): void
    {
        config([
            'app.tenant_url_mode' => 'subdomain',
            'mobile_api.app_links.ios_app_ids' => 'ABCDE12345.de.example.app',
            'mobile_api.app_links.android_package' => 'de.example.app',
            'mobile_api.app_links.android_sha256_fingerprints' => 'AA:BB:CC',
        ]);
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => DB::table('tenant_branding')->where('tenant_id', $tenant->id)->update(['custom_domain' => 'schule-'.$tenant->slug.'.example.test']));

        $patterns = collect($this->get('http://'.$tenant->slug.'.localhost/.well-known/apple-app-site-association')->assertOk()
            ->json('applinks.details.0.components'))->pluck('/');
        $this->assertContains('/reset-password/*', $patterns);
        // Die Bestätigung der Kontolöschung gibt es nur auf der zentralen Domain.
        $this->assertNotContains('/app/konto-loeschen', $patterns);
        $this->get('http://'.$tenant->slug.'.localhost/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'de.example.app');

        // Eigene Domain der Schule und tiefere Subdomains: kein Mail-Link führt dorthin.
        $this->get('http://schule-'.$tenant->slug.'.example.test/.well-known/apple-app-site-association')->assertNotFound();
        $this->get('http://schule-'.$tenant->slug.'.example.test/.well-known/assetlinks.json')->assertNotFound();
        $this->get('http://a.'.$tenant->slug.'.localhost/.well-known/assetlinks.json')->assertNotFound();
    }

    /** Apples „/“-Muster: „*“ steht für beliebig viele Zeichen, „?“ für genau eines. */
    private function appleMatches(string $pattern, string $path): bool
    {
        $regex = '#^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '#')).'$#';

        return preg_match($regex, $path) === 1;
    }
}
