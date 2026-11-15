<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\NewPasswordController;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantBranding;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\EmailChangeRequested;
use App\Services\ParticipantInviter;
use App\Support\LoginThrottle;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zum Security-Audit, Paket Anmeldung und Konto-Härtung. */
class AuthHardeningAuditTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function fresh(User $user): ?User
    {
        return $this->onAdmin(fn () => User::find($user->id));
    }

    private function loginFrom(string $ip, $tenant, string $email, string $password)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withSession(['dev_tenant_slug' => $tenant->slug])
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    // --- SEC-W-1: gezielte Konto-Sperre ----------------------------------

    public function test_failed_attempts_from_other_networks_do_not_lock_out_the_correct_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);

        // Verteilter Angriff: 25 Fehlversuche aus 25 Netzen.
        foreach (range(1, 25) as $i) {
            $this->loginFrom("10.{$i}.0.1", $tenant, $user->email, 'falsch');
            auth()->logout();
        }
        $this->assertTrue(RateLimiter::tooManyAttempts(LoginThrottle::accountKey($user->email), LoginThrottle::ACCOUNT_ATTEMPTS));

        // Die Person selbst aus ihrem (sauberen) Netz kommt weiter herein.
        $this->loginFrom('198.51.100.7', $tenant, $user->email, 'geheim123!')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, RateLimiter::attempts(LoginThrottle::accountKey($user->email)), 'erfolgreiche Anmeldung setzt den Kontozähler zurück');
    }

    public function test_under_attack_each_network_only_gets_a_few_guesses(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!')]);
        foreach (range(1, LoginThrottle::ACCOUNT_ATTEMPTS) as $i) {
            $this->loginFrom("10.{$i}.0.1", $tenant, $user->email, 'falsch');
        }

        // Neues Netz: drei Versuche, danach wird gar nicht mehr geprüft --
        // auch das richtige Passwort nicht (kein Orakel für den Angreifer).
        foreach (range(1, LoginThrottle::NETWORK_ATTEMPTS_UNDER_ATTACK) as $i) {
            $this->loginFrom("203.0.113.{$i}", $tenant, $user->email, 'falsch')->assertSessionHasErrors('email');
        }
        $error = $this->loginFrom('203.0.113.99', $tenant, $user->email, 'geheim123!')->getSession()->get('errors')->first('email');
        $this->assertStringStartsWith('Zu viele Anmeldeversuche', $error);
        $this->assertGuest();
    }

    public function test_networks_are_cut_to_24_and_48_bits(): void
    {
        $this->assertSame('192.0.2.0/24', LoginThrottle::network('192.0.2.77'));
        $this->assertSame('2001:db8:1::/48', LoginThrottle::network('2001:db8:1:2::5'));
        $this->assertSame('unbekannt', LoginThrottle::network(null));
    }

    public function test_a_password_reset_clears_the_account_counter(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        foreach (range(1, 30) as $i) {
            LoginThrottle::failed($user->email, "10.{$i}.0.1");
        }

        $this->onAdmin(fn () => NewPasswordController::completeReset(User::find($user->id), 'neues-passwort-123'));

        $this->assertSame(0, RateLimiter::attempts(LoginThrottle::accountKey($user->email)));
    }

    // --- SEC-W-2: Adressänderung durch den Support ----------------------

    public function test_support_email_change_plus_reset_keeps_two_factor_and_ends_sessions(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'admin', ['remember_token' => 'alt-r']);
        $oldEmail = $user->email;
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save());
        $this->onAdmin(fn () => DB::table('sessions')->insert(['id' => 'sec-w2-'.Str::random(8), 'user_id' => $user->id, 'payload' => base64_encode('x'), 'last_activity' => now()->timestamp]));
        $newEmail = 'neu-'.Str::lower(Str::random(8)).'@example.test';

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test', 'last_name' => 'Admin', 'email' => $newEmail, 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $changed = $this->fresh($user);
        $this->assertNull($changed->email_verified_at);
        $this->assertNotNull($changed->email_ever_verified_at);
        $this->assertNotSame('alt-r', $changed->remember_token);
        $this->onAdmin(fn () => $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count()));
        Notification::assertSentTo($user, EmailChangeRequested::class, function ($notification, $channels, $notifiable) use ($oldEmail, $newEmail) {
            return $notifiable->email === $oldEmail && str_contains((string) $notification->toMail($notifiable)->render(), 'Der Support hat')
                && str_contains(implode(' ', $notification->toMail($notifiable)->introLines), $newEmail);
        });
        $this->assertTrue($this->onAdmin(fn () => AuditLog::where('action', 'superadmin.user.update')->where('entity_id', $user->id)->exists()));

        // Reset über die neue Adresse: 2FA bleibt (Entfernen nur über resetTwoFactor).
        $token = $this->onAdmin(fn () => Password::broker()->createToken(User::find($user->id)));
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'token' => $token, 'email' => $newEmail, 'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->fresh($user)->hasTwoFactor());

        $this->onAdmin(fn () => AuditLog::where('action', 'superadmin.user.update')->where('entity_id', $user->id)->delete());
    }

    public function test_the_ever_verified_mark_cannot_be_cleared(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['email_verified_at' => null, 'email_ever_verified_at' => null]));

        $this->assertNotNull($this->fresh($user)->email_ever_verified_at);
    }

    // --- SEC-W-5: 2FA-Pflicht nur mit Bestätigung aufheben -------------

    public function test_lifting_the_team_two_factor_requirement_needs_password_and_code(): void
    {
        $tenant = $this->createTestTenant();
        $secret = Totp::generateSecret();
        $owner = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);
        $this->onAdmin(fn () => User::findOrFail($owner->id)->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save());
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['require_team_two_factor' => true]));
        $required = fn () => $this->onAdmin(fn () => TenantBranding::find($tenant->id)->require_team_two_factor);

        $this->actingAsInTenant($this->fresh($owner), $tenant)->patch('/admin/team/two-factor-policy', ['require_team_two_factor' => '0'])->assertSessionHasErrors('two_factor');
        $this->actingAsInTenant($this->fresh($owner), $tenant)->patch('/admin/team/two-factor-policy', ['require_team_two_factor' => '0', 'current_password' => 'geheim123!', 'code' => '000000'])->assertSessionHasErrors('two_factor');
        $this->assertTrue($required());

        $this->actingAsInTenant($this->fresh($owner), $tenant)->patch('/admin/team/two-factor-policy', [
            'require_team_two_factor' => '0', 'current_password' => 'geheim123!', 'code' => Totp::at($secret, time()),
        ])->assertSessionHasNoErrors();
        $this->assertFalse($required());
    }

    // --- SEC-W-3: Einladungs-Orakel --------------------------------------

    public function test_inviting_an_existing_account_looks_like_inviting_a_new_address(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant(), 'learner');
        $newEmail = 'neu-'.Str::lower(Str::random(8)).'@example.test';
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));

        $first = [];
        $again = [];
        foreach (['Bestand Person' => $existing->email, 'Neue Person' => $newEmail] as $name => $email) {
            $first[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => $name, 'email' => $email])->assertSessionHasNoErrors()->getSession()->get('status');
            $again[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => $name, 'email' => $email])->getSession()->get('status');
        }
        $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $newEmail)->value('id'));

        $this->assertSame(['Einladung verschickt.', 'Einladung verschickt.'], $first);
        $this->assertSame([ParticipantInviter::ALREADY_INVITED_STATUS, ParticipantInviter::ALREADY_INVITED_STATUS], $again);

        // Beide stehen gleich als offene Einladung in der Teilnehmerliste.
        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $page->assertSee('Bestand Person')->assertSee($existing->email)->assertSee('Neue Person')->assertSee($newEmail);
        $this->assertSame(2, substr_count($page->getContent(), '>Einladung offen<'));
        $this->assertSame('Bestand Person', $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->value('name')));
    }

    public function test_a_code_given_with_the_invitation_gets_the_same_answer_for_both_cases(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant(), 'learner');
        $course = $this->existingCourse('SRC');
        $codes = ['AUD1-'.Str::upper(Str::random(4)).'-ABCD', 'AUD2-'.Str::upper(Str::random(4)).'-ABCD'];
        $this->onAdmin(fn () => collect($codes)->each(fn ($c) => Coupon::create(['code' => $c, 'course_id' => $course->id, 'tenant_id' => $tenant->id])));
        RateLimiter::clear(ParticipantInviter::dailyKey($tenant));

        try {
            $emails = [$existing->email, 'neu-'.Str::lower(Str::random(8)).'@example.test'];
            $invalid = [];
            $valid = [];
            foreach ($emails as $i => $email) {
                $invalid[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'X', 'email' => $email, 'code' => 'GIBT-ES-NICHT-'.$i])
                    ->getSession()->get('errors')->first('code');
                RateLimiter::clear('invite-mail:'.$tenant->id.':'.sha1($email));
            }
            $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $emails[1])->value('id'));
            $this->assertSame(['Dieser Code ist ungültig oder wurde bereits eingelöst.', 'Dieser Code ist ungültig oder wurde bereits eingelöst.'], $invalid);

            foreach (['b1-'.Str::lower(Str::random(6)).'@example.test' => $codes[0]] as $email => $code) {
                $valid[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Neu', 'email' => $email, 'code' => $code])->assertSessionHasNoErrors()->getSession()->get('status');
                $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $email)->value('id'));
            }
            $other = $this->createTenantUser($this->createTestTenant(), 'learner');
            $valid[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Bestand', 'email' => $other->email, 'code' => $codes[1]])->assertSessionHasNoErrors()->getSession()->get('status');

            $this->assertSame($valid[0], $valid[1]);
            $this->assertSame('Einladung verschickt.'.ParticipantInviter::CODE_NOTE, $valid[0]);
            // Neues Konto: Code weiterhin direkt eingelöst.
            $this->assertTrue($this->onAdmin(fn () => Coupon::where('code', $codes[0])->first()->isRedeemed()));
        } finally {
            $this->onAdmin(function () use ($codes) {
                Entitlement::whereIn('source_reference', $codes)->delete();
                Coupon::whereIn('code', $codes)->delete();
            });
        }
    }

    public function test_reinviting_a_new_team_account_answers_like_an_open_invitation(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant(), 'learner');
        $newEmail = 'team-'.Str::lower(Str::random(8)).'@example.test';

        $answers = [];
        foreach ([$existing->email, $newEmail] as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Team Neu', 'email' => $email, 'role' => 'instructor'])->assertSessionHas('status', 'Einladung verschickt.');
            $answers[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Team Neu', 'email' => $email, 'role' => 'instructor'])
                ->assertSessionHasNoErrors()->getSession()->get('status');
        }
        $this->createdUserIds[] = $this->onAdmin(fn () => User::where('email', $newEmail)->value('id'));

        $this->assertSame([ParticipantInviter::ALREADY_INVITED_STATUS, ParticipantInviter::ALREADY_INVITED_STATUS], $answers);
        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), '>Einladung offen<'));
    }
}
