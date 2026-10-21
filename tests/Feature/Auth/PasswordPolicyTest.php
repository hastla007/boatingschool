<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N3: ohne eigene Password::defaults() griff nur Laravels
 * eingebauter Minimalstandard (8 Zeichen, sonst ungeprüft).
 */
class PasswordPolicyTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_password_shorter_than_ten_characters_is_rejected_on_registration(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Test User',
            'email' => 'short-'.uniqid().'@example.test',
            'password' => 'short123',
            'password_confirmation' => 'short123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_a_ten_character_password_is_accepted_on_registration(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Test User',
            'email' => 'long-'.uniqid().'@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $this->assertAuthenticated();
        $this->createdUserIds[] = auth()->id();
    }
}
