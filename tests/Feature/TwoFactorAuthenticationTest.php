<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei-Faktor-Anmeldung (TOTP): einrichten, Anmeldung mit Code bzw.
 * Wiederherstellungscode, abschalten.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function fresh(User $user): User
    {
        return $this->onAdmin(fn () => User::findOrFail($user->id));
    }

    public function test_setting_up_two_factor_requires_a_valid_code_and_shows_recovery_codes(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->post('/two-factor/enable')->assertRedirect(route('two-factor.show'));
        $secret = $this->fresh($admin)->two_factor_secret;
        $this->assertSame(32, strlen($secret));
        $this->assertFalse($this->fresh($admin)->hasTwoFactor(), 'erst nach Bestätigung aktiv');

        $page = $this->actingAsInTenant($admin, $tenant)->get('/two-factor')->assertOk();
        $page->assertSee('<svg', false);

        $this->actingAsInTenant($admin, $tenant)->post('/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAsInTenant($admin, $tenant)->post('/two-factor/confirm', ['code' => Totp::at($secret, time())])
            ->assertSessionHas('two_factor.recovery_codes', fn ($codes) => count($codes) === 8);
        $this->assertTrue($this->fresh($admin)->hasTwoFactor());
    }

    public function test_login_asks_for_the_code_and_accepts_a_recovery_code_once(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);
        $secret = Totp::generateSecret();
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCDE-FGHIJ')],
        ])->save());

        // Passwort richtig: noch nicht angemeldet, weiter zur Code-Abfrage.
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', ['email' => $user->email, 'password' => 'geheim123!'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Bestätigungscode', false);

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => Totp::at($secret, time())])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // Wiederherstellungscode: gilt genau einmal.
        auth()->logout();
        $this->flushSession();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', ['email' => $user->email, 'password' => 'geheim123!']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-fghij'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $this->fresh($user)->two_factor_recovery_codes);

        // Ohne vorheriges Passwort keine Abfrage.
        auth()->logout();
        $this->flushSession();
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    }

    public function test_switching_off_requires_the_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'admin', ['password' => Hash::make('geheim123!')]);
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save());

        $this->actingAsInTenant($this->fresh($user), $tenant)->delete('/two-factor', ['password' => 'falsch'])->assertSessionHasErrors('password');
        $this->assertTrue($this->fresh($user)->hasTwoFactor());
        $this->actingAsInTenant($this->fresh($user), $tenant)->delete('/two-factor', ['password' => 'geheim123!'])->assertSessionHasNoErrors();
        $this->assertFalse($this->fresh($user)->hasTwoFactor());
    }
}
