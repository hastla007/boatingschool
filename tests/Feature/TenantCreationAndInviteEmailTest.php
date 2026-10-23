<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B18: eine neu von Superadmin\TenantController::store() oder
 * Admin\ParticipantController::invite() angelegte Person bekam ein
 * zufälliges, niemandem bekanntes Passwort -- ohne jede Mail dazu gab es
 * keine Möglichkeit, sich je einzuloggen. Beide Stellen nutzten außerdem
 * keine 'lowercase'-Validierung für die E-Mail-Adresse (anders als
 * RegisteredUserController), sodass derselbe Mensch mit abweichender
 * Schreibweise ein zweites Konto hätte anlegen können.
 */
class TenantCreationAndInviteEmailTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_creating_a_tenant_sends_a_password_set_link_to_the_new_owner(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();

        // Eindeutig pro Testlauf statt eines festen Strings: Password::
        // sendResetLink() drosselt (throttle) wiederholte Anfragen für
        // dieselbe Adresse innerhalb kurzer Zeit -- ein fester String hätte
        // bei mehreren Testläufen kurz hintereinander keine neue
        // Benachrichtigung mehr ausgelöst.
        $slug = 'b18-neue-schule-'.uniqid();
        $email = 'b18-neue-schule-admin-'.uniqid().'@example.test';

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => $slug,
            'name' => 'B18 Neue Schule',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => $email,
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($slug, $email) {
            $tenant = Tenant::where('slug', $slug)->first();
            $this->createdTenantIds[] = $tenant->id;
            $admin = User::where('email', $email)->first();
            $this->createdUserIds[] = $admin->id;

            Notification::assertSentTo($admin, ResetPassword::class);
        });
    }

    public function test_creating_a_tenant_rejects_an_uppercase_admin_email(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => 'b18-schule-zwei',
            'name' => 'B18 Schule Zwei',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => 'B18-Admin@Example.test',
        ]);

        $response->assertSessionHasErrors('admin_email');
        $this->assertDatabaseMissing('app_user', ['email' => 'B18-Admin@Example.test']);
        $this->assertDatabaseMissing('tenant', ['slug' => 'b18-schule-zwei']);
    }

    public function test_inviting_a_new_participant_sends_a_password_set_link(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        // Eindeutig pro Testlauf statt eines festen Strings, siehe
        // test_creating_a_tenant_sends_a_password_set_link_to_the_new_owner().
        $email = 'b18-teilnehmer-'.uniqid().'@example.test';

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Teilnehmer',
            'email' => $email,
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($email) {
            $learner = User::where('email', $email)->first();
            $this->createdUserIds[] = $learner->id;

            Notification::assertSentTo($learner, ResetPassword::class);
        });
    }

    /**
     * Bug-Review N1: anders als Superadmin\TenantController::store() und
     * Admin\ParticipantController::invite() (siehe oben) verschickte
     * Superadmin\UserController::store() keine Einladungsmail -- das neu
     * angelegte Konto blieb mit einem zufälligen, niemandem bekannten
     * Passwort auf status=invited stehen und konnte sich nie einloggen
     * (LoginRequest verlangt status=active).
     */
    public function test_creating_a_standalone_user_via_the_superadmin_sends_a_password_set_link(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();

        $email = 'n1-neuer-nutzer-'.uniqid().'@example.test';

        $response = $this->actingAs($superadmin)->post('/superadmin/users', [
            'first_name' => 'Neuer',
            'last_name' => 'Nutzer',
            'email' => $email,
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($email) {
            $user = User::where('email', $email)->first();
            $this->createdUserIds[] = $user->id;

            $this->assertSame('invited', $user->status);
            Notification::assertSentTo($user, ResetPassword::class);
        });
    }

    public function test_inviting_a_participant_rejects_an_uppercase_email(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Teilnehmer',
            'email' => 'B18-Teilnehmer@Example.test',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('app_user', ['email' => 'B18-Teilnehmer@Example.test']);
    }
}
