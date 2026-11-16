<?php

namespace Tests\Feature\Api;

use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\AgeConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Altersbestätigung über die App: Das Festlegen des ersten Passworts eines
 * eingeladenen Kontos (POST /auth/password/reset) nimmt Einladungen an --
 * dafür einmalig age_confirmed, wie im Web. /me meldet den Stand.
 */
class AgeConfirmationApiTest extends MobileApiTestCase
{
    private const PASSWORD = 'Ein-sicheres-Passwort-2026';

    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach (User::whereIn('email', $this->emails)->get() as $user) {
                DB::table('account_setup_tokens')->where('email', $user->email)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('api_device_session')->where('user_id', $user->id)->delete();
                $user->delete();
            }
        });
        parent::tearDown();
    }

    /** Neues Konto über die Teilnehmer-Einladung der Schule. */
    private function invitedAccount(): array
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $email = $this->emails[] = 'app-alter-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Person', 'email' => $email])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());

        return [$tenant, $user];
    }

    private function reset(string $email, string $token, array $extra = [])
    {
        return $this->postJson('/api/v1/auth/password/reset', $extra + [
            'email' => $email, 'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'invite' => true,
        ]);
    }

    public function test_an_invited_account_confirms_the_age_once_when_setting_the_password_in_the_app(): void
    {
        [$tenant, $user] = $this->invitedAccount();
        $token = $this->onAdmin(fn () => Password::broker('invites')->createToken($user));
        $accepted = fn () => $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $user->email)->value('accepted_at'));

        $this->reset($user->email, $token)->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.age_confirmed.0', AgeConfirmation::MESSAGE);
        $this->reset($user->email, $token, ['age_confirmed' => false])->assertStatus(422)->assertJsonPath('errors.age_confirmed.0', AgeConfirmation::MESSAGE);
        // Nichts angenommen, nichts aktiviert.
        $this->assertSame('invited', $this->onAdmin(fn () => User::find($user->id)->status));
        $this->assertNull($accepted());

        $this->reset($user->email, $token, ['age_confirmed' => true])->assertNoContent();
        $fresh = $this->onAdmin(fn () => User::find($user->id));
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->age_confirmed_at);
        $this->assertNotNull($accepted());

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$this->apiLogin($fresh, self::PASSWORD)])
            ->assertOk()->assertJsonPath('data.user.age_confirmed', true);
    }

    public function test_an_invalid_link_does_not_reveal_an_invited_account_through_the_age_question(): void
    {
        [, $user] = $this->invitedAccount();

        $this->reset($user->email, 'falscher-token-falscher-token')->assertStatus(422)
            ->assertJsonPath('code', 'PASSWORD_RESET_INVALID_OR_EXPIRED')->assertJsonMissingPath('errors.age_confirmed');
    }

    public function test_an_invited_account_is_asked_even_without_the_invite_flag(): void
    {
        [, $user] = $this->invitedAccount();
        $token = $this->onAdmin(fn () => Password::broker()->createToken($user));

        $this->reset($user->email, $token, ['invite' => false])->assertStatus(422)->assertJsonPath('errors.age_confirmed.0', AgeConfirmation::MESSAGE);
        $this->reset($user->email, $token, ['invite' => false, 'age_confirmed' => '1'])->assertNoContent();
        $this->assertNotNull($this->onAdmin(fn () => User::find($user->id)->age_confirmed_at));
    }

    public function test_an_already_confirmed_or_active_account_needs_no_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $confirmed = $this->createTenantUser($tenant, 'learner');
        $token = $this->onAdmin(fn () => Password::broker()->createToken(User::find($confirmed->id)));
        $this->postJson('/api/v1/auth/password/reset', ['email' => $confirmed->email, 'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertNoContent();

        // Aktives Bestandskonto ohne Bestätigung: normales Zurücksetzen fragt nicht (wie im Web) ...
        $legacy = $this->createTenantUser($tenant, 'learner', ['age_confirmed_at' => null]);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$this->apiLogin($legacy)])->assertOk()->assertJsonPath('data.user.age_confirmed', false);
        $token = $this->onAdmin(fn () => Password::broker()->createToken(User::find($legacy->id)));
        $this->postJson('/api/v1/auth/password/reset', ['email' => $legacy->email, 'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertNoContent();
        $this->assertNull($this->onAdmin(fn () => User::find($legacy->id)->age_confirmed_at));

        // ... übernimmt eine mitgeschickte Bestätigung aber.
        $token = $this->onAdmin(fn () => Password::broker()->createToken(User::find($legacy->id)));
        $this->postJson('/api/v1/auth/password/reset', ['email' => $legacy->email, 'token' => $token, 'password' => self::PASSWORD.'x', 'password_confirmation' => self::PASSWORD.'x', 'age_confirmed' => true])
            ->assertNoContent();
        $this->assertNotNull($this->onAdmin(fn () => User::find($legacy->id)->age_confirmed_at));
    }
}
