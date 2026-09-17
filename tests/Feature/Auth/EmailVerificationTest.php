<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);

        $response = $this->actingAsInTenant($user, $tenant)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAsInTenant($user, $tenant)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAsInTenant($user, $tenant)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
