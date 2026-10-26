<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
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

    /**
     * Bug-Review R4-J: die drei Coupon-Tests hier löschten ihren jeweils
     * fest codierten Testcode (ohne uniqid()-Suffix) bisher erst NACH ihren
     * eigenen Assertions im Testkörper selbst -- schlug eine Assertion
     * (oder ein künftiger Regressions-Fix) fehl, lief dieses Aufräumen nie,
     * und der Code blieb dauerhaft (nur per UNIQUE-Constraint verwaist)
     * stehen. Ein späterer Testlauf scheiterte dann an genau diesem Code
     * mit einer UniqueConstraintViolationException, unabhängig vom
     * eigentlich zu prüfenden Verhalten. tearDown() räumt jetzt
     * unabhängig vom Testergebnis auf, analog zu $createdCodes in
     * ParticipantInviteTest.
     */
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
        $this->asTenant($tenant, function () use ($tenant, $learner, $course) {
            $this->assertDatabaseMissing('entitlement', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
            ]);
        });
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
        $this->createdCouponCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $importResponse = $this->actingAsInTenant($instructor, $tenant)->post('/admin/coupons/import', ['codes' => $code]);
        $importResponse->assertForbidden();

        $assignResponse = $this->actingAsInTenant($instructor, $tenant)->post("/admin/coupons/{$coupon->id}/assign", ['user_id' => $learner->id]);
        $assignResponse->assertForbidden();

        $this->assertFalse($coupon->fresh()->isRedeemed());
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

        $email = 'instructor-invited-'.uniqid().'@example.test';
        $invite = $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/invite', [
            'name' => 'Von Instructor Eingeladen',
            'email' => $email,
        ]);
        $invite->assertRedirect();

        // Bug-Review Testisolation: die Einladung legt einen neuen
        // app_user an, der über die Tenant-Löschung hinweg bestehen
        // bleibt (tenant_user kaskadiert weg, app_user selbst nicht).
        $this->onAdmin(function () use ($email) {
            $invited = User::where('email', $email)->first();
            if ($invited) {
                $this->createdUserIds[] = $invited->id;
            }
        });
    }

    /**
     * Die "Teilnehmer"-UI zeigt einem Instructor nur role=learner an (siehe
     * AdminDashboardController) -- die Routen /admin/participants/{user}
     * (GET+PATCH) kannten diese Einschränkung bisher nicht und akzeptierten
     * jede User-ID derselben Bootsschule, auch die eines Admins/Owners.
     */
    public function test_instructor_cannot_view_or_edit_an_admins_data(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $admin = $this->createTenantUser($tenant, 'admin', ['street' => 'Admin-Straße 1']);

        $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$admin->id}")->assertNotFound();

        $this->actingAsInTenant($instructor, $tenant)->patch("/admin/participants/{$admin->id}", [
            'street' => 'Übernommen',
        ])->assertNotFound();

        $this->assertSame('Admin-Straße 1', $admin->fresh()->street);
    }

    public function test_instructor_can_still_view_and_edit_a_learners_data(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$learner->id}")->assertOk();

        $this->actingAsInTenant($instructor, $tenant)->patch("/admin/participants/{$learner->id}", [
            'street' => 'Lerner-Straße 1',
        ])->assertRedirect();

        $this->assertSame('Lerner-Straße 1', $learner->fresh()->street);
    }

    public function test_owner_can_still_view_and_edit_an_admins_data(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');

        $this->actingAsInTenant($owner, $tenant)->get("/admin/participants/{$admin->id}")->assertOk();

        $this->actingAsInTenant($owner, $tenant)->patch("/admin/participants/{$admin->id}", [
            'street' => 'Von Owner Geändert',
        ])->assertRedirect();

        $this->assertSame('Von Owner Geändert', $admin->fresh()->street);
    }

    /**
     * Bug-Review N11: isAdminFor() behandelt owner/admin als gleich
     * vertrauenswürdig -- ein Admin konnte damit genauso die Stammdaten
     * des Owners (oder eines anderen Admins) ändern wie ein Instructor
     * die eines Admins. Nur der Owner darf eine Nicht-Lerner-
     * Mitgliedschaft bearbeiten.
     */
    public function test_admin_cannot_edit_the_owners_data(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $owner = $this->createTenantUser($tenant, 'owner', ['street' => 'Owner-Straße 1']);

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$owner->id}", [
            'street' => 'Übernommen',
        ])->assertNotFound();

        $this->assertSame('Owner-Straße 1', $owner->fresh()->street);
    }

    public function test_admin_cannot_edit_another_admins_data(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $otherAdmin = $this->createTenantUser($tenant, 'admin', ['street' => 'Anderer-Admin-Straße 1']);

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$otherAdmin->id}", [
            'street' => 'Übernommen',
        ])->assertNotFound();

        $this->assertSame('Anderer-Admin-Straße 1', $otherAdmin->fresh()->street);
    }

    /**
     * Ein eigener Code ist für den Kursbesuch gedacht -- ein Instructor ist
     * eine Lehrkraft, kein Kursteilnehmer, und sollte deshalb keine Codes
     * für sich selbst einlösen können.
     */
    public function test_instructor_cannot_redeem_a_coupon(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-INSTRUCTOR-REDEEM';
        $this->createdCouponCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id]));

        $this->actingAsInTenant($instructor, $tenant)->get('/coupons/redeem')->assertForbidden();

        $response = $this->actingAsInTenant($instructor, $tenant)->post('/coupons/redeem', ['code' => $code]);
        $response->assertForbidden();

        $this->assertFalse($coupon->fresh()->isRedeemed());
    }

    public function test_a_learner_can_still_redeem_a_coupon(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-LEARNER-REDEEM';
        $this->createdCouponCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id]));

        $this->actingAsInTenant($learner, $tenant)->get('/coupons/redeem')->assertOk();

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);
        $response->assertRedirect();

        $this->assertTrue($coupon->fresh()->isRedeemed());
    }
}
