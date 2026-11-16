<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review: für eine offene Einladung reservierte Codes
 * (tenant_invitation.coupon_code) wurden von „Kurs freischalten“,
 * „Widerruf aufheben“ und „Verlängern“ verbraucht (oldest() ohne
 * Ausschluss) -- die eingeladene Person bekam beim Annehmen dann
 * stillschweigend keinen Kurszugang. Die Übersicht zählte sie als frei.
 */
class ReservedInvitationCodeIsNotConsumedTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCouponIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => Coupon::whereIn('id', $this->createdCouponIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function coupon(Tenant $tenant, CourseDefinition $course, bool $renewal = false, int $ageDays = 0): Coupon
    {
        $coupon = $this->onAdmin(function () use ($tenant, $course, $renewal, $ageDays) {
            $coupon = Coupon::create(['code' => 'TEST-RES-'.uniqid(), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => $renewal]);
            Coupon::where('id', $coupon->id)->update(['created_at' => now()->subDays($ageDays)]);

            return $coupon->fresh();
        });
        $this->createdCouponIds[] = $coupon->id;

        return $coupon;
    }

    private function reserve(Tenant $tenant, User $admin, Coupon $coupon): void
    {
        $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id, 'email' => 'reserviert-'.uniqid().'@example.test', 'role' => 'learner',
            'invited_by_user_id' => $admin->id, 'coupon_code' => $coupon->code,
        ]));
    }

    public function test_granting_access_skips_a_code_reserved_for_an_open_invitation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $reserved = $this->coupon($tenant, $course, ageDays: 5);
        $this->reserve($tenant, $admin, $reserved);

        $page = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertSame(0, (int) $page->viewData('freeCourseCouponCounts')->get($course->id, 0));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasErrors('course_id');
        $this->assertNull($this->onAdmin(fn () => $reserved->fresh()->redeemed_at));

        $free = $this->coupon($tenant, $course);
        $page = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertSame(1, (int) $page->viewData('freeCourseCouponCounts')->get($course->id, 0));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->onAdmin(fn () => $reserved->fresh()->redeemed_at));
        $this->assertNotNull($this->onAdmin(fn () => $free->fresh()->redeemed_at));
    }

    public function test_an_expired_invitation_no_longer_reserves_its_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->coupon($tenant, $course);
        $this->reserve($tenant, $admin, $coupon);
        $this->onAdmin(fn () => TenantInvitation::where('coupon_code', $coupon->code)->update(['created_at' => now()->subDays(8)]));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($this->onAdmin(fn () => $coupon->fresh()->redeemed_at));
    }

    public function test_undoing_a_revocation_skips_a_reserved_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'revoked']));
        $reserved = $this->coupon($tenant, $course);
        $this->reserve($tenant, $admin, $reserved);

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'reactivate'])
            ->assertSessionHasErrors('action');
        $this->assertNull($this->onAdmin(fn () => $reserved->fresh()->redeemed_at));
        $this->assertSame('revoked', $this->onAdmin(fn () => $entitlement->fresh()->status));
    }

    public function test_extending_skips_a_reserved_renewal_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_until' => now()->addDays(5)]));
        $reserved = $this->coupon($tenant, $course, renewal: true);
        $this->reserve($tenant, $admin, $reserved);

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), ['action' => 'extend'])
            ->assertSessionHasErrors('action');
        $this->assertNull($this->onAdmin(fn () => $reserved->fresh()->redeemed_at));
    }
}
