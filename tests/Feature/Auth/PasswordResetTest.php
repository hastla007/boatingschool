<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use InteractsWithTenants;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($tenant) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $tenant) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });
    }
}
