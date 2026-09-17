<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use InteractsWithTenants;

    public function test_profile_page_is_displayed(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'updated-'.uniqid().'@example.test',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password']);

        $response->assertSessionHasErrorsIn('userDeletion', 'password')->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
