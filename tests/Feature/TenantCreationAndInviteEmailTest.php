<?php

namespace Tests\Feature;

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

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => 'b18-neue-schule',
            'name' => 'B18 Neue Schule',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => 'b18-neue-schule-admin@example.test',
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () {
            $tenant = \App\Models\Tenant::where('slug', 'b18-neue-schule')->first();
            $this->createdTenantIds[] = $tenant->id;
            $admin = User::where('email', 'b18-neue-schule-admin@example.test')->first();
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

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Teilnehmer',
            'email' => 'b18-teilnehmer@example.test',
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () {
            $learner = User::where('email', 'b18-teilnehmer@example.test')->first();
            $this->createdUserIds[] = $learner->id;

            Notification::assertSentTo($learner, ResetPassword::class);
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
