<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview H3: Finanz-/Vertrauens-relevante Aktionen (Kurse
 * kostenlos freischalten/widerrufen, Branding, Webshop-Links/Kursauswahl,
 * Addons, Kursempfehlungen, Coupon-Bestand) bleiben Owner/Admin
 * vorbehalten -- ein Instructor kann sie weder über die Route noch über
 * die UI (versteckte Tabs, siehe admin/dashboard.blade.php) auslösen.
 */
class InstructorPermissionsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_instructor_cannot_grant_a_free_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($instructor, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_instructor_cannot_revoke_an_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($instructor, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'revoke',
        ]);

        $response->assertForbidden();
        $this->assertSame('active', $entitlement->fresh()->status);
    }

    public function test_instructor_cannot_update_branding(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $response = $this->actingAsInTenant($instructor, $tenant)->patch('/admin/branding', [
            'primary_color' => '#ff0000',
        ]);

        $response->assertForbidden();
    }

    public function test_instructor_cannot_update_course_selection_or_webshop_links(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $response = $this->actingAsInTenant($instructor, $tenant)->patch('/admin/courses', [
            'enabled' => [],
            'renewal_webshop_link' => 'https://evil.test/shop',
        ]);

        $response->assertForbidden();
    }

    public function test_instructor_cannot_import_or_assign_coupons(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INSTRUCTOR-BLOCK';
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $importResponse = $this->actingAsInTenant($instructor, $tenant)->post('/admin/coupons/import', ['codes' => $code]);
        $importResponse->assertForbidden();

        $assignResponse = $this->actingAsInTenant($instructor, $tenant)->post("/admin/coupons/{$coupon->id}/assign", ['user_id' => $learner->id]);
        $assignResponse->assertForbidden();

        $this->assertFalse($coupon->fresh()->isRedeemed());
        $this->onAdmin(fn () => Coupon::where('code', $code)->delete());
    }

    public function test_instructor_cannot_update_addons_or_course_promo_banners(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $this->actingAsInTenant($instructor, $tenant)->patch('/admin/addons', [])->assertForbidden();
        $this->actingAsInTenant($instructor, $tenant)->post('/admin/course-promo-banners', [
            'title' => 'Hack', 'body' => 'x', 'course_ids' => [],
        ])->assertForbidden();
    }

    public function test_instructor_does_not_see_restricted_tabs_but_owner_does(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $owner = $this->createTenantUser($tenant, 'owner');

        $asInstructor = $this->actingAsInTenant($instructor, $tenant)->get('/admin');
        $asInstructor->assertOk();
        $asInstructor->assertDontSee('Coupon-Codes', false);
        $asInstructor->assertDontSee('>Branding<', false);
        $asInstructor->assertDontSee('>Addons<', false);
        $asInstructor->assertDontSee('Kursauswahl', false);
        $asInstructor->assertDontSee('Kursempfehlungen', false);
        $asInstructor->assertSee('Orte');
        $asInstructor->assertSee('Teilnehmer');

        $asOwner = $this->actingAsInTenant($owner, $tenant)->get('/admin');
        $asOwner->assertOk();
        $asOwner->assertSee('Coupon-Codes');
        $asOwner->assertSee('Branding');
        $asOwner->assertSee('Kursauswahl');
    }

    public function test_instructor_can_still_view_the_dashboard_and_manage_participants(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $response = $this->actingAsInTenant($instructor, $tenant)->get('/admin');
        $response->assertOk();

        $invite = $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/invite', [
            'name' => 'Von Instructor Eingeladen',
            'email' => 'instructor-invited-'.uniqid().'@example.test',
        ]);
        $invite->assertRedirect();
    }
}
