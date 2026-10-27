<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview S4: Sperren/Entsperren einer Bootsschule oder eines
 * Kontos, das Erteilen/Entziehen von is_superadmin, die Rollenzuweisung
 * über die Superadmin-Nutzerseite und das Erzeugen von Coupon-Codes
 * schrieben bisher keine AuditLog-Zeile -- im Unterschied zu den längst
 * bestehenden AuditLog::record()-Aufrufen für Bootsschul-Admin-Aktionen
 * (z. B. entitlement.*, coupon.redeem, participant.update).
 */
class SuperadminAuditLoggingTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCouponCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCouponCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_suspending_a_tenant_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        $this->actingAs($superadmin)->patch("/superadmin/tenants/{$tenant->id}", [
            'name' => $tenant->name,
            'status' => 'suspended',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $tenant) {
            $log = AuditLog::where('action', 'superadmin.tenant.update')
                ->where('entity_id', $tenant->id)->first();

            $this->assertNotNull($log);
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('active', $log->before_data['status']);
            $this->assertSame('suspended', $log->after_data['status']);
        });
    }

    public function test_suspending_a_user_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'suspended',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $user) {
            $log = AuditLog::where('action', 'superadmin.user.update')
                ->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('active', $log->before_data['status']);
            $this->assertSame('suspended', $log->after_data['status']);
        });
    }

    public function test_granting_is_superadmin_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'active',
            'is_superadmin' => '1',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $user) {
            $log = AuditLog::where('action', 'superadmin.user.update')
                ->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertFalse($log->before_data['is_superadmin']);
            $this->assertTrue($log->after_data['is_superadmin']);
        });
    }

    public function test_assigning_a_tenant_role_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/memberships", [
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $tenant, $user) {
            $log = AuditLog::where('action', 'superadmin.user.membership_update')
                ->where('tenant_id', $tenant->id)->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('learner', $log->before_data['role']);
            $this->assertSame('owner', $log->after_data['role']);
        });
    }

    public function test_generating_coupon_codes_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'course:'.$course->id,
            'quantity' => 2,
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $course) {
            $this->createdCouponCodes = Coupon::where('course_id', $course->id)->pluck('code')->all();

            $log = AuditLog::where('action', 'superadmin.coupon.create')->latest('id')->first();

            $this->assertNotNull($log);
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame(2, $log->after_data['quantity']);
            $this->assertCount(2, $log->after_data['codes']);
        });
    }
}
