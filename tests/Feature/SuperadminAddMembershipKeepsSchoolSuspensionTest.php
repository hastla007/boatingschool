<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TenantUser;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Rollenänderung hebt Sperre der Bootsschule auf):
 * Superadmin\UserController::addMembership() setzte per updateOrCreate()
 * auch bei einer bestehenden Mitgliedschaft status=active und hob damit
 * stillschweigend eine Sperre der Bootsschule auf.
 */
class SuperadminAddMembershipKeepsSchoolSuspensionTest extends TestCase
{
    use InteractsWithTenants;

    public function test_changing_the_role_of_a_suspended_member_keeps_the_suspension(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['status' => 'suspended']));

        $response = $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/memberships", [
            'tenant_id' => $tenant->id,
            'role' => 'instructor',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'gesperrt'));

        $this->onAdmin(function () use ($tenant, $user) {
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
            $this->assertSame('instructor', $membership->role);
            $this->assertSame('suspended', $membership->status);

            $log = AuditLog::where('tenant_id', $tenant->id)->where('action', 'superadmin.user.membership_update')->latest('created_at')->first();
            $this->assertSame('suspended', $log->after_data['status']);
        });
    }

    public function test_a_new_membership_starts_active(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $otherTenant = $this->createTestTenant();
        $user = $this->createTenantUser($otherTenant, 'learner');

        $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/memberships", [
            'tenant_id' => $tenant->id,
            'role' => 'learner',
        ])->assertRedirect();

        $this->onAdmin(function () use ($tenant, $user) {
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
            $this->assertNotNull($membership);
            $this->assertSame('active', $membership->status);
        });
    }
}
