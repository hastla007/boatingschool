<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * QA-L-1/2/3/5: Codes und Freischalten der Schule behandelten Teilzugänge
 * (entitlement.capabilities) und beendete Gruppenzugänge wie volle bzw.
 * widerrufene Zugänge; das Web zeigte Teilzugänge als gebucht.
 */
class PartialAndGroupAccessRedemptionTest extends TestCase
{
    use InteractsWithTenants;

    private array $couponIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => Coupon::whereIn('id', $this->couponIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_course_code_on_a_partial_entitlement_creates_full_access(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $partial = $this->entitlement($tenant, $learner, $course, ['capabilities' => ['exam']]);
        $coupon = $this->coupon($tenant, $course);

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])->assertSessionHasNoErrors();

        $this->assertTrue($this->hasFullAccess($tenant, $learner, $course));
        $this->assertSame(['exam'], $this->onAdmin(fn () => Entitlement::find($partial->id)->capabilities));
        $this->assertNotNull($this->onAdmin(fn () => $coupon->fresh()->redeemed_at));
    }

    public function test_renewal_code_ignores_an_unlimited_partial_entitlement(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $this->entitlement($tenant, $learner, $course, ['capabilities' => ['exam']]);
        $full = $this->entitlement($tenant, $learner, $course, [
            'status' => 'expired', 'valid_from' => now()->subYear(), 'valid_until' => now()->subDay(),
        ]);
        $coupon = $this->coupon($tenant, $course, ['is_renewal' => true]);

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])->assertSessionHasNoErrors();

        $renewed = $this->onAdmin(fn () => Entitlement::find($full->id));
        $this->assertSame('active', $renewed->status);
        $this->assertTrue($renewed->valid_until->isFuture());
        $this->assertTrue($this->hasFullAccess($tenant, $learner, $course));
    }

    public function test_admin_unlock_with_an_active_partial_entitlement_grants_full_access(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->entitlement($tenant, $learner, $course, ['capabilities' => ['smarttrainer']]);
        $coupon = $this->coupon($tenant, $course);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->hasFullAccess($tenant, $learner, $course));
        $this->assertNotNull($this->onAdmin(fn () => $coupon->fresh()->redeemed_at));
    }

    public function test_reactivation_keeps_the_earlier_access_start(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $start = now()->subDays(60)->startOfSecond();
        $expired = $this->entitlement($tenant, $learner, $course, ['status' => 'expired', 'valid_from' => $start, 'valid_until' => now()->subDay()]);
        $coupon = $this->coupon($tenant, $course);

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])->assertSessionHasNoErrors();

        $row = $this->onAdmin(fn () => Entitlement::find($expired->id));
        $this->assertSame('active', $row->status);
        $this->assertTrue($row->valid_from->equalTo($start), "valid_from wurde auf {$row->valid_from} zurückgesetzt.");
    }

    public function test_an_ended_group_access_does_not_block_codes_or_the_school_unlock(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $admin = $this->createTenantUser($tenant, 'owner');
        // Beendeter Gruppenzugang, wie ihn LearningGroupService hinterlässt.
        $group = $this->entitlement($tenant, $learner, $course, [
            'source_type' => 'group', 'source_reference' => 'group:x:y', 'status' => 'expired',
            'valid_from' => now()->subMonth(), 'valid_until' => now()->subMinute(),
        ]);
        $coupon = $this->coupon($tenant, $course);

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])->assertSessionHasNoErrors();
        $this->assertTrue($this->hasFullAccess($tenant, $learner, $course));
        // Die Gruppenzeile bleibt der Lerngruppe zugeordnet.
        $this->assertSame('group', $this->onAdmin(fn () => Entitlement::find($group->id)->source_type));

        $other = $this->createTenantUser($tenant, 'learner');
        $this->entitlement($tenant, $other, $course, [
            'source_type' => 'group', 'source_reference' => 'group:x:z', 'status' => 'expired',
            'valid_from' => now()->subMonth(), 'valid_until' => now()->subMinute(),
        ]);
        $this->coupon($tenant, $course);
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $other->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->hasFullAccess($tenant, $other, $course));
    }

    public function test_web_lists_only_full_access_and_offers_courses_with_partial_access(): void
    {
        [$tenant, $learner, $course] = $this->setUpLearner();
        $this->entitlement($tenant, $learner, $course, ['capabilities' => ['exam']]);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses')->assertOk();
        $this->assertFalse($response->viewData('courses')->contains(fn ($entry) => $entry['course']->id === $course->id));
        $this->assertTrue($response->viewData('lockedCourses')->contains('id', $course->id));
        $response->assertSee('Teilzugang in der App');

        $dashboard = $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk();
        $this->assertFalse($dashboard->viewData('courses')->contains('id', $course->id));
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpLearner(): array
    {
        $tenant = $this->createTestTenant();

        return [$tenant, $this->createTenantUser($tenant, 'learner'), $this->existingCourse('SRC')];
    }

    private function entitlement(Tenant $tenant, User $user, CourseDefinition $course, array $attributes): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create($attributes + [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subDay(),
        ]));
    }

    private function coupon(Tenant $tenant, CourseDefinition $course, array $attributes = []): Coupon
    {
        $coupon = $this->onAdmin(fn () => Coupon::create($attributes + [
            'code' => 'QA-L-'.strtoupper(uniqid()), 'course_id' => $course->id, 'tenant_id' => $tenant->id,
        ]));
        $this->couponIds[] = $coupon->id;

        return $coupon;
    }

    private function hasFullAccess(Tenant $tenant, User $user, CourseDefinition $course): bool
    {
        return $this->asTenant($tenant, fn () => app(EntitlementService::class)->hasAccess($tenant, $user, $course));
    }
}
