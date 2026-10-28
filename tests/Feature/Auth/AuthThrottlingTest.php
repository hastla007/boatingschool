<?php

namespace Tests\Feature\Auth;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N2: register, login und forgot-password hatten kein
 * (oder nur ein pro E-Mail greifendes) Limit -- Credential-Stuffing bzw.
 * Massenregistrierung über viele E-Mails von derselben IP blieb möglich.
 */
class AuthThrottlingTest extends TestCase
{
    use InteractsWithTenants;

    public function test_registration_is_rate_limited_per_ip(): void
    {
        $tenant = $this->createTestTenant();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
                'name' => 'Test',
                'email' => 'nope-'.$i.'@example.test',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Test',
            'email' => 'one-more@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(429);
    }

    public function test_login_is_rate_limited_per_ip_across_different_emails(): void
    {
        $tenant = $this->createTestTenant();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
                'email' => 'nope-'.$i.'@example.test',
                'password' => 'wrong',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', [
            'email' => 'one-more@example.test',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }

    public function test_forgot_password_is_rate_limited_per_ip_across_different_emails(): void
    {
        $tenant = $this->createTestTenant();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', [
                'email' => 'nope-'.$i.'@example.test',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', [
            'email' => 'one-more@example.test',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Sicherheitsreview S2: POST /reset-password hatte als einzige sensible
     * Route in dieser Gruppe gar keine throttle-Middleware -- unbegrenzt
     * viele Token-/E-Mail-Kombinationen pro IP waren möglich.
     */
    public function test_reset_password_submission_is_rate_limited_per_ip(): void
    {
        $tenant = $this->createTestTenant();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
                'token' => 'invalid-token',
                'email' => 'nope-'.$i.'@example.test',
                'password' => 'password1234',
                'password_confirmation' => 'password1234',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'one-more@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertStatus(429);
    }
}
