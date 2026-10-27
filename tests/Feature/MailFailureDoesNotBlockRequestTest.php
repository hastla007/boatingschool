<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B31: Mailversand lief synchron im Request -- ein SMTP-Fehler
 * ergab HTTP 500, obwohl die eigentliche Schreiboperation (neue
 * Bootsschule/neuer Teilnehmer/neue E-Mail-Adresse) schon erfolgreich
 * gespeichert war. Alle drei Stellen laufen den Versand jetzt über
 * App\Support\BestEffortMail::send() -- ein Fehler wird geloggt, bricht den
 * Request aber nicht mehr ab.
 */
class MailFailureDoesNotBlockRequestTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_creating_a_tenant_still_succeeds_when_the_invite_mail_fails(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('SMTP down'));

        $superadmin = $this->createSuperAdmin();
        $slug = 'b31-schule-'.uniqid();
        $email = 'b31-admin-'.uniqid().'@example.test';

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => $slug,
            'name' => 'B31 Schule',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => $email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Bootsschule angelegt') && str_contains($status, 'nicht versendet'));

        $this->onAdmin(function () use ($slug, $email) {
            $tenant = Tenant::where('slug', $slug)->first();
            $this->assertNotNull($tenant, 'Tenant wurde trotz Mail-Fehler nicht gespeichert.');
            $this->createdTenantIds[] = $tenant->id;

            $admin = User::where('email', $email)->first();
            $this->assertNotNull($admin, 'Admin-Konto wurde trotz Mail-Fehler nicht gespeichert.');
            $this->createdUserIds[] = $admin->id;
        });
    }

    public function test_inviting_a_new_participant_still_succeeds_when_the_invite_mail_fails(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $email = 'b31-teilnehmer-'.uniqid().'@example.test';

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Teilnehmer',
            'email' => $email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'fehlgeschlagen'));

        $this->onAdmin(function () use ($email) {
            $learner = User::where('email', $email)->first();
            $this->assertNotNull($learner, 'Teilnehmer wurde trotz Mail-Fehler nicht gespeichert.');
            $this->createdUserIds[] = $learner->id;
        });
    }

    public function test_inviting_an_existing_account_still_succeeds_when_the_invite_mail_fails(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $existingUser = $this->onAdmin(fn () => User::forceCreate([
            'email' => 'b31-bestand-'.uniqid().'@example.test',
            'display_name' => 'Bestandskonto',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]));
        $this->createdUserIds[] = $existingUser->id;

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Bestandskonto',
            'email' => $existingUser->email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'fehlgeschlagen'));

        $this->onAdmin(function () use ($existingUser) {
            $this->assertTrue(
                TenantInvitation::where('email', $existingUser->email)->exists(),
                'Einladung wurde trotz Mail-Fehler nicht gespeichert.'
            );
        });
    }

    public function test_changing_the_profile_email_still_succeeds_when_the_confirmation_mail_fails(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['first_name' => 'Test', 'last_name' => 'User']);
        $newEmail = 'b31-neu-'.uniqid().'@example.test';

        $response = $this->actingAsInTenant($user, $tenant)->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $newEmail,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Profil gespeichert') && str_contains($status, 'nicht versendet'));

        $this->assertSame($newEmail, $user->fresh()->pending_email, 'pending_email wurde trotz Mail-Fehler nicht gespeichert.');
    }

    /**
     * Bug-Review SMTP: Registrierung und "Passwort vergessen" liefen bisher
     * noch ungeschützt synchron -- ein SMTP-Fehler ergab HTTP 500, obwohl
     * das neue Konto (hier) bzw. gar keine Schreiboperation (siehe unten)
     * davon unberührt bleibt.
     */
    public function test_registering_still_succeeds_when_the_verification_mail_fails(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $email = 'smtp-register-'.uniqid().'@example.test';

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'SMTP Test',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertGuest();

        $this->onAdmin(function () use ($email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, 'Konto wurde trotz Mail-Fehler nicht gespeichert.');
            $this->createdUserIds[] = $user->id;
        });
    }

    /**
     * Bug-Review F9: SupportEmailVerificationController::sendVerificationMail()
     * rief Mail::to(...)->send(...) bisher direkt und ungefangen auf -- ein
     * SMTP-Fehler ergab HTTP 500, egal ob der Request vom erneuten Senden
     * des Bestätigungslinks oder von einem Branding-Update (das die
     * Support-E-Mail geändert hat) ausgelöst wurde.
     */
    public function test_resending_the_support_email_verification_link_still_succeeds_when_the_mail_fails(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->onAdmin(fn () => $tenant->branding->update(['support_email' => 'support@example.test']));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/branding/support-email/verification-notification');

        $response->assertRedirect();
        $this->assertFalse($response->isServerError());
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'konnte nicht versendet werden'));
    }

    public function test_updating_the_branding_still_succeeds_when_the_support_email_verification_mail_fails(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'support_email' => 'neue-support-adresse@example.test',
            'exam_readiness_threshold_percent' => 50,
        ]);

        $response->assertRedirect();
        $this->assertFalse($response->isServerError());
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Branding aktualisiert') && str_contains($status, 'nicht versendet'));
        $this->onAdmin(fn () => $this->assertSame('neue-support-adresse@example.test', $tenant->branding->fresh()->support_email));
    }

    public function test_requesting_a_password_reset_link_still_returns_success_when_the_mail_fails(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('SMTP down'));

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertFalse($response->isServerError());
    }
}
