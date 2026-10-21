<?php

namespace Tests\Feature;

use App\Models\TenantUser;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M1: ein gesperrtes Konto (app_user.status) oder eine
 * gesperrte Mitgliedschaft (tenant_user.status) behielt bisher vollen
 * Zugang -- weder der Login noch roleForTenant() prüften den Status, und
 * Bootsschulen hatten keine Möglichkeit, einen Teilnehmer zu entfernen.
 */
class AccountSuspensionTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'suspended']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_active_account_can_still_log_in(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_suspended_membership_blocks_access_to_that_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->update(['status' => 'suspended']));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_a_school_admin_can_suspend_and_reactivate_a_participant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $suspend = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'suspended',
        ]);
        $suspend->assertRedirect();
        $this->assertSame('suspended', TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status'));

        $blocked = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');
        $blocked->assertForbidden();

        $reactivate = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'active',
        ]);
        $reactivate->assertRedirect();
        $this->assertSame('active', TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status'));

        $allowed = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');
        $allowed->assertOk();
    }

    public function test_an_instructor_cannot_suspend_a_participant(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($instructor, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertForbidden();
        $this->assertSame('active', TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status'));
    }

    public function test_an_admin_cannot_suspend_their_own_membership(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$admin->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(422);
        $this->assertSame('active', TenantUser::where('tenant_id', $tenant->id)->where('user_id', $admin->id)->value('status'));
    }

    public function test_superadmin_suspending_a_user_ends_their_sessions(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk();
        $this->onAdmin(fn () => \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'fake-session-for-test',
            'user_id' => $user->id,
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]));

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }
}
