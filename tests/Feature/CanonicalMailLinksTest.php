<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Models\User;
use App\Notifications\ConfirmPendingEmail;
use App\Notifications\DuplicateRegistrationAttempt;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview S1 (Rest) und Mail-Flut: signierte Links in E-Mails
 * zeigen nie auf die Custom Domain des auslösenden Requests, und die
 * Registrierung verschickt je Adresse höchstens eine Mail pro Stunde.
 */
class CanonicalMailLinksTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_verification_link_points_to_the_central_domain_and_works_there(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'stale.example-school.de']));
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);

        $this->actingAs($user)->post('http://stale.example-school.de/email/verification-notification')->assertRedirect();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith('http://'.config('app.central_domain').'/verify-email/', $url);
        $this->actingAs($user)->get($url)->assertRedirect();
        $this->assertNotNull($this->onAdmin(fn () => User::find($user->id)->email_verified_at));
    }

    public function test_the_email_change_link_points_to_the_school_subdomain(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'stale2.example-school.de']));
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($user)->patch('http://stale2.example-school.de/profile', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'neu-'.uniqid().'@example.test', 'current_password' => 'password',
        ])->assertRedirect();

        Notification::assertSentOnDemand(ConfirmPendingEmail::class, function ($notification, $channels, $notifiable) use ($tenant) {
            return str_starts_with($notification->toMail($notifiable)->actionUrl, 'http://'.$tenant->slug.'.'.config('app.central_domain').'/');
        });
    }

    public function test_registration_mails_an_existing_address_at_most_once_per_hour(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $existing = $this->createTenantUser($tenant, 'learner');
        RateLimiter::clear('registration-mail:'.sha1(strtolower($existing->email)));

        foreach (range(1, 5) as $i) {
            $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
                'name' => 'x', 'email' => $existing->email, 'password' => 'password1234', 'password_confirmation' => 'password1234',
            ])->assertRedirect();
        }

        $count = 0;
        Notification::assertSentOnDemand(DuplicateRegistrationAttempt::class, function () use (&$count) {
            $count++;

            return true;
        });
        $this->assertSame(1, $count);
    }
}
