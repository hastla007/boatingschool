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

    /**
     * Bug-Review R3-1 (Folgefehler): ResolveTenant behandelte /login als
     * mandantenunabhängig bisher nur, wenn GAR KEIN Tenant aus dem Host
     * aufgelöst werden konnte (zentrale Domain) -- auf einer echten
     * Bootsschul-Subdomain wurde stattdessen deren realer Tenant-Kontext
     * gesetzt. app_user_select_visibility (Migration 000461) macht eine
     * Zeile dann nur sichtbar, wenn der Nutzer bereits Mitglied DIESER
     * Bootsschule ist -- ein Login mit korrektem Passwort schlug deshalb
     * fehl, sobald jemand /login direkt über die Subdomain einer
     * Bootsschule aufrief, bei der er (noch) kein Mitglied ist (z. B.
     * beim allerersten Login direkt über einen Einladungs-Link für eine
     * ANDERE, noch nicht beigetretene Bootsschule).
     */
    public function test_users_can_authenticate_on_a_tenant_subdomain_they_are_not_yet_a_member_of(): void
    {
        $homeTenant = $this->createTestTenant();
        $otherTenant = $this->createTestTenant();
        $user = $this->createTenantUser($homeTenant);

        $response = $this->withSession(['dev_tenant_slug' => $otherTenant->slug])->post('/login', [
            'email' => $user->email,
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
