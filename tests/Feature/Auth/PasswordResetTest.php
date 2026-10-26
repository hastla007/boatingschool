<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
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
                'password' => 'password1234',
                'password_confirmation' => 'password1234',
            ]);

            $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });
    }

    /**
     * Bug-Review NEU-7: app:create-superadmin empfiehlt einem Konto, dessen
     * E-Mail-Adresse nie bestätigt wurde, genau diesen Weg ("z. B.
     * Passwort-Reset-Link") als Nachweis -- ohne dass ein erfolgreicher
     * Reset email_verified_at setzt, hätte der Befehl danach trotzdem
     * weiter abgelehnt, obwohl der Mailbox-Besitz gerade bewiesen wurde.
     */
    public function test_a_successful_password_reset_verifies_a_previously_unverified_email(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['email_verified_at' => null]);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $tenant) {
            $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password1234',
                'password_confirmation' => 'password1234',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertNotNull($user->fresh()->email_verified_at);

        $exitCode = Artisan::call('app:create-superadmin', ['email' => $user->email]);
        $this->assertSame(0, $exitCode, 'app:create-superadmin muss nach dem empfohlenen Passwort-Reset-Nachweis erfolgreich befördern.');
    }

    /**
     * Bug-Review R4-6: jede Stelle, die eine E-Mail-Adresse anlegt oder
     * ändert, erzwingt per 'lowercase'-Regel, dass sie klein geschrieben
     * gespeichert wird -- Password::sendResetLink() suchte aber bisher per
     * exaktem, Groß-/Kleinschreibung-sensitivem Vergleich. Tippt der Nutzer
     * seine eigene Adresse hier mit (nur) einem Großbuchstaben ein (z. B.
     * Autokapitalisierung der Handy-Tastatur), fand das Konto bisher niemand.
     */
    public function test_a_reset_link_is_still_found_when_the_email_is_typed_with_different_case(): void
    {
        Notification::fake();

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => strtoupper($user->email)]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * Sicherheitsreview M3: Laravels Standardverhalten unterscheidet sich
     * strukturell, je nachdem ob die Adresse existiert -- ein Feld-Fehler
     * samt zurückgegebenem Input bei einer unbekannten Adresse, ein reiner
     * Erfolgs-Flash bei einer bekannten. Selbst mit bewusst neutral
     * formuliertem Text verrät allein diese unterschiedliche Antwortform
     * schon, ob eine Adresse existiert.
     */
    public function test_requesting_a_reset_link_for_an_unknown_email_looks_identical_to_a_known_one(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $known = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => $user->email]);
        $known->assertSessionHasNoErrors();
        $knownStatus = $known->baseResponse->getSession()->get('status');

        $unknown = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', ['email' => 'niemand-registriertes@example.test']);
        $unknown->assertSessionHasNoErrors();
        $unknownStatus = $unknown->baseResponse->getSession()->get('status');

        $this->assertNotNull($knownStatus);
        $this->assertSame($knownStatus, $unknownStatus);
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
    }
}
