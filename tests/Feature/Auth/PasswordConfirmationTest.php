<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_password_can_be_confirmed(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
