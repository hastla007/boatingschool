<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Passwort-Throttle eingeloggter Nutzer): drei Routen prüfen
 * das aktuelle Passwort eines bereits eingeloggten Nutzers (Hash::check
 * intern über current_password bzw. Auth::guard()->validate()), hatten
 * aber -- anders als /login, /reset-password (Sicherheitsreview S2) und
 * profile.update (Bug-Review R4-B) -- keine throttle-Middleware. Eine
 * gestohlene/entführte Session hätte das echte Passwort beliebig oft
 * erraten können.
 */
class LoggedInPasswordThrottlingTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_password_confirmation_endpoint_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($user, $tenant)->post('/confirm-password', ['password' => 'wrong-password']);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($user, $tenant)->post('/confirm-password', ['password' => 'wrong-password']);

        $response->assertStatus(429);
    }

    public function test_the_password_update_endpoint_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($user, $tenant)->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'ein-neues-sicheres-passwort',
                'password_confirmation' => 'ein-neues-sicheres-passwort',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($user, $tenant)->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'ein-neues-sicheres-passwort',
            'password_confirmation' => 'ein-neues-sicheres-passwort',
        ]);

        $response->assertStatus(429);
    }

    public function test_the_account_deletion_endpoint_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'wrong-password']);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'wrong-password']);

        $response->assertStatus(429);
        $this->assertNotNull($user->fresh());
    }
}
