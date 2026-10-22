<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Kurse X Tage für Teilnehmer verfügbar" (Default 365, Kursauswahl-Seite):
 * ein neu per Coupon-Code eingelöstes Entitlement bekommt dieses Fenster
 * als valid_until (siehe CouponRedemptionService) -- nach Ablauf greift die
 * bereits bestehende Filterung in EntitlementService::activeEntitlements()
 * ganz von selbst, der Kurs verschwindet aus der aktiven Kursliste. Auf der
 * Kursübersicht sieht der Teilnehmer dafür statt "Jetzt kaufen" entweder den
 * Verlängerungs-Codes-Webshop-Link (renewal_webshop_link) oder, ohne Link,
 * den Hinweis, sich an die Bootsschule zu wenden.
 */
class CourseValidityTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    /** Alle sitewide freigegebenen Kurs-IDs -- Standardwert für "enabled[]". */
    private function allSiteEnabledCourseIds(): array
    {
        return $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)->pluck('id')->toArray());
    }

    private function redeemCourseCoupon(CourseDefinition $course, $tenant, $learner): void
    {
        $code = 'E2E-VALIDITY-'.strtoupper(uniqid());
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code])
            ->assertSessionHasNoErrors();
    }

    public function test_new_tenants_default_to_365_days_validity(): void
    {
        $tenant = $this->createTestTenant();

        $this->assertSame(365, $tenant->branding->course_validity_days);
    }

    public function test_a_school_admin_can_set_the_validity_days_and_renewal_link(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
            'course_validity_days' => 30,
            'renewal_webshop_link' => 'https://shop.example.test/verlaengerung',
        ]);

        $response->assertRedirect();
        $this->assertSame(30, $tenant->branding->fresh()->course_validity_days);
        $this->assertSame('https://shop.example.test/verlaengerung', $tenant->branding->fresh()->renewal_webshop_link);
    }

    public function test_clearing_the_renewal_link_removes_it(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $enabled = $this->allSiteEnabledCourseIds();

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $enabled,
            'renewal_webshop_link' => 'https://shop.example.test/verlaengerung',
        ]);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $enabled,
            'renewal_webshop_link' => '',
        ]);

        $this->assertNull($tenant->branding->fresh()->renewal_webshop_link);
    }

    public function test_redeeming_a_coupon_sets_valid_until_based_on_the_configured_validity_days(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
            'course_validity_days' => 30,
        ]);

        $this->redeemCourseCoupon($course, $tenant, $learner);

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());

        $this->assertNotNull($entitlement->valid_until);
        $this->assertTrue($entitlement->valid_until->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_an_expired_course_no_longer_counts_as_active_access(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->redeemCourseCoupon($course, $tenant, $learner);

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        // Statt $entitlement->update(...): eine zuvor geladene Model-Instanz
        // trägt die Connection fest, die beim Laden "default" war -- siehe
        // ExpireAbandonedExamSessionsTest für die ausführliche Begründung.
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => now()->subDays(40), 'valid_until' => now()->subDay()]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertForbidden();
    }

    public function test_course_overview_shows_the_renewal_link_for_an_expired_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
            'renewal_webshop_link' => 'https://shop.example.test/verlaengerung',
        ]);

        $this->redeemCourseCoupon($course, $tenant, $learner);
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => now()->subDays(40), 'valid_until' => now()->subDay()]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertSee('Zugang abgelaufen');
        $response->assertSee('https://shop.example.test/verlaengerung');
        $response->assertSee('Verlängerungs-Code kaufen');
    }

    public function test_course_overview_shows_the_default_note_for_an_expired_course_without_a_renewal_link(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->redeemCourseCoupon($course, $tenant, $learner);
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => now()->subDays(40), 'valid_until' => now()->subDay()]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertSee('Zugang abgelaufen');
        $response->assertSee('Bitte Verlängerungscode bei Deiner Bootsschule anfragen!');
        $response->assertDontSee('Verlängerungs-Code kaufen');
    }

    public function test_a_still_valid_course_does_not_show_the_renewal_note(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->redeemCourseCoupon($course, $tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertDontSee('Zugang abgelaufen');
        $response->assertDontSee('Bitte Verlängerungscode bei Deiner Bootsschule anfragen!');
    }
}
