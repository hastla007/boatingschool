<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
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

        Notification::fake();

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'updated-'.uniqid().'@example.test',
            'phone' => '+49 30 1234567',
            'street' => 'Hafenstraße 1',
            'postal_code' => '12345',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('Test User', $user->name);
        $this->assertSame('+49 30 1234567', $user->phone);
        $this->assertSame('Hafenstraße 1', $user->street);
        $this->assertSame('12345', $user->postal_code);
        $this->assertSame('Hamburg', $user->city);
        $this->assertSame('Deutschland', $user->country);
        $this->assertNull($user->email_verified_at);

        // Eine geänderte E-Mail-Adresse muss erneut verifiziert werden.
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        Notification::fake();

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_country_must_be_one_of_the_supported_options(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'country' => 'Elbonien',
        ]);

        $response->assertSessionHasErrors('country');
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
