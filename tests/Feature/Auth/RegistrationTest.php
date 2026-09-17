<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_registration_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_are_attached_as_learner_of_the_current_tenant(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $userId = auth()->id();
        $this->createdUserIds[] = $userId;

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $userId,
            'role' => 'learner',
        ]);
    }
}
