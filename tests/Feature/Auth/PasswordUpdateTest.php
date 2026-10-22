<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use InteractsWithTenants;

    public function test_password_can_be_updated(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasErrorsIn('updatePassword', 'current_password')->assertRedirect('/profile');
    }

    public function test_changing_the_password_ends_other_sessions_but_keeps_the_current_one(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->onAdmin(fn () => DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]));

        $response = $this->actingAsInTenant($user, $tenant)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }
}
