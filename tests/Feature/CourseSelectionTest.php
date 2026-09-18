<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\TenantCourseDisabled;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei unabhängige Sichtbarkeits-Schalter für einen Kurs:
 * - Mandant (Bootsschul-Admin): welche sitewide freigegebenen Kurse er selbst anbietet.
 * - Superadmin: sitewide an/aus, gewinnt immer -- keine Bootsschule kann einen
 *   sitewide deaktivierten Kurs aktivieren, und bestehende Zugänge werden ausgeblendet.
 */
class CourseSelectionTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->where('code', 'like', 'CST-%')->first();
            if ($course) {
                $course->modules()->detach();
                $course->delete();
            }

            // Defensiv: eine fehlschlagende Assertion mitten im Test würde sonst
            // den geteilten Katalogkurs für alle nachfolgenden Tests deaktiviert lassen.
            CourseDefinition::withoutGlobalScopes()->whereIn('code', ['SRC', 'UBI'])->update(['site_enabled' => true]);
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_school_admin_can_deselect_a_course_and_it_disappears_from_the_overview(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $before = $this->actingAsInTenant($learner, $tenant)->get('/courses');
        $before->assertOk();
        $before->assertSee($course->name);

        $allCourseIds = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)->pluck('id')->toArray());
        $keep = array_values(array_diff($allCourseIds, [$course->id]));

        $update = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', ['enabled' => $keep]);
        $update->assertRedirect();

        $this->assertDatabaseHas('tenant_course_disabled', ['tenant_id' => $tenant->id, 'course_id' => $course->id]);

        $after = $this->actingAsInTenant($learner, $tenant)->get('/courses');
        $after->assertOk();
        $after->assertDontSee($course->name);
    }

    public function test_a_deselected_course_also_blocks_direct_access_despite_an_existing_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertForbidden();
    }

    public function test_reselecting_a_course_restores_visibility(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $response->assertForbidden();

        $this->onAdmin(fn () => TenantCourseDisabled::where('tenant_id', $tenant->id)->where('course_id', $course->id)->delete());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $response->assertOk();
    }

    public function test_a_sitewide_disabled_course_is_hidden_and_inaccessible_regardless_of_tenant_selection(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $before = $this->actingAsInTenant($learner, $tenant)->get('/courses');
        $before->assertSee($course->name);

        $this->actingAs($superadmin)->patch("/superadmin/courses/{$course->id}", [
            'name' => $course->name,
            'course_type' => $course->course_type,
            'status' => $course->status,
        ]);

        $this->assertDatabaseHas('course_definition', ['id' => $course->id, 'site_enabled' => false]);

        $after = $this->actingAsInTenant($learner, $tenant)->get('/courses');
        $after->assertOk();
        $after->assertDontSee($course->name);

        $direct = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $direct->assertForbidden();

        // reset for other tests relying on the shared seeded UBI course
        $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('id', $course->id)->update(['site_enabled' => true]));
    }

    public function test_a_sitewide_disabled_course_does_not_appear_in_a_tenants_selectable_list(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('UBI');

        $this->actingAs($superadmin)->patch("/superadmin/courses/{$course->id}", [
            'name' => $course->name,
            'course_type' => $course->course_type,
            'status' => $course->status,
        ]);

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin');

        $response->assertOk();
        $response->assertDontSee($course->name);

        $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('id', $course->id)->update(['site_enabled' => true]));
    }

    public function test_redeeming_a_coupon_for_a_sitewide_disabled_course_is_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));

        $this->actingAs($superadmin)->patch("/superadmin/courses/{$course->id}", [
            'name' => $course->name,
            'course_type' => $course->course_type,
            'status' => $course->status,
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('entitlement', ['tenant_id' => $tenant->id, 'course_id' => $course->id]);

        $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->where('id', $course->id)->update(['site_enabled' => true]));
    }

    public function test_redeeming_a_coupon_for_a_course_the_tenant_has_deselected_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('entitlement', ['tenant_id' => $tenant->id, 'course_id' => $course->id]);
    }

    public function test_a_non_superadmin_cannot_toggle_the_sitewide_switch(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/superadmin/courses/{$course->id}", [
            'name' => $course->name,
            'course_type' => $course->course_type,
            'status' => $course->status,
            'site_enabled' => '0',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('course_definition', ['id' => $course->id, 'site_enabled' => true]);
    }
}
