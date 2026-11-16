<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Models\User;
use App\Support\Totp;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei-Faktor-Seiten: Team (Inhaber, Admins, Ausbilder) und Superadmin
 * werden gesiezt, Teilnehmer geduzt (User::prefersFormalAddress()).
 */
class TwoFactorFormalAddressTest extends TestCase
{
    use InteractsWithTenants;

    public function test_team_is_addressed_formally_and_learners_informally(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantBranding::updateOrCreate(['tenant_id' => $tenant->id], ['require_team_two_factor' => true]));

        $this->actingAsInTenant($instructor, $tenant)->get('/admin')->assertRedirect(route('two-factor.show'))
            ->assertSessionHas('status', 'Ihre Bootsschule verlangt für das Team die Zwei-Faktor-Anmeldung. Bitte richten Sie sie jetzt ein.');
        $this->actingAsInTenant($instructor, $tenant)->get('/two-factor')->assertOk()
            ->assertSee('Das schützt Ihr Konto, auch wenn jemand Ihr Passwort kennt.')
            ->assertSee('Zum Einrichten Ihr Passwort')->assertDontSee('dein Passwort');

        $this->actingAsInTenant($learner, $tenant)->get('/two-factor')->assertOk()
            ->assertSee('Das schützt dein Konto, auch wenn jemand dein Passwort kennt.')
            ->assertSee('Zum Einrichten dein Passwort')->assertDontSee('Ihr Passwort');
    }

    public function test_the_code_prompt_after_the_password_follows_the_role(): void
    {
        $tenant = $this->createTestTenant();
        foreach (['admin' => 'Geben Sie den 6-stelligen Code aus Ihrer Authenticator-App ein.', 'learner' => 'Gib den 6-stelligen Code aus deiner Authenticator-App ein.'] as $role => $text) {
            $user = $this->createTenantUser($tenant, $role);
            $user = $this->onAdmin(function () use ($user) {
                $fresh = User::findOrFail($user->id);
                $fresh->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

                return $fresh;
            });

            $this->withSession([
                'dev_tenant_slug' => $tenant->slug,
                'two_factor.login' => ['id' => $user->id, 'at' => now()->timestamp, 'pw' => hash('sha256', (string) $user->getAuthPassword())],
            ])->get('/two-factor-challenge')->assertOk()->assertSee($text);
        }
    }
}
