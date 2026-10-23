<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_login_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Bug-Review N17: Auth::attempt() vergleicht die E-Mail standardmäßig
     * groß-/kleinschreibungssensitiv -- auf dem Handy wird der erste
     * Buchstabe beim Eintippen oft automatisch großgeschrieben (Auto-
     * kapitalisierung), was den Login trotz korrektem Passwort scheitern
     * ließ.
     */
    public function test_users_can_authenticate_with_a_differently_cased_email(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email' => 'hans.mueller@example.test']);

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => 'Hans.Mueller@example.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
