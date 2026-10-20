<?php

namespace Tests\Feature;

use App\Models\CoursePromoBanner;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschulen können pro Kurs einen frei formulierten Empfehlungsbanner
 * aktivieren, der für einen anderen/weiterführenden Kurs wirbt -- anders
 * als der vorgefertigte Addon-Banner (siehe TenantAddonTest) gehört der
 * Text hier komplett der Bootsschule. Siehe admin/tabs/course-promo-
 * banners.blade.php und CoursePromoBannerController.
 */
class CoursePromoBannerTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_school_admin_can_activate_and_configure_a_promo_banner(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => [
                    'active' => '1',
                    'headline' => 'Mach weiter mit der Segelausbildung!',
                    'description' => 'Erweitere deinen Schein um die Segelprüfung.',
                    'cta_label' => 'Jetzt entdecken',
                    'price_label' => '149 €',
                    'discount_percent' => '10',
                    'coupon_code' => 'SEGEL10',
                    'offer_url' => 'https://shop.example.test/segel-ergaenzung',
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('course_promo_banner', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Mach weiter mit der Segelausbildung!',
            'description' => 'Erweitere deinen Schein um die Segelprüfung.',
            'cta_label' => 'Jetzt entdecken',
            'price_label' => '149 €',
            'discount_percent' => 10,
            'coupon_code' => 'SEGEL10',
            'offer_url' => 'https://shop.example.test/segel-ergaenzung',
        ]);
    }

    public function test_deactivating_a_promo_banner_removes_its_configuration(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => ['active' => '1', 'headline' => 'Test', 'offer_url' => 'https://shop.example.test/x'],
            ],
        ]);

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => ['active' => '0'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('course_promo_banner', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_course_promo_banners_tab_lists_courses(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=course-promo-banners');

        $response->assertOk();
        $response->assertSee($course->name);
    }

    public function test_promo_banner_configuration_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($adminA, $tenantA)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => ['active' => '1', 'headline' => 'Nur für A', 'offer_url' => 'https://shop.example.test/a'],
            ],
        ]);

        $this->assertDatabaseHas('course_promo_banner', ['tenant_id' => $tenantA->id, 'headline' => 'Nur für A']);
        $this->assertDatabaseMissing('course_promo_banner', ['tenant_id' => $tenantB->id, 'course_id' => $course->id]);
    }

    public function test_banner_is_shown_on_the_configured_courses_page(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Mach weiter mit der Segelausbildung!',
            'description' => 'Erweitere deinen Schein um die Segelprüfung.',
            'cta_label' => 'Jetzt entdecken',
            'price_label' => '149 €',
            'discount_percent' => 10,
            'coupon_code' => 'SEGEL10',
            'offer_url' => 'https://shop.example.test/segel-ergaenzung',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Mach weiter mit der Segelausbildung!');
        $response->assertSee('Erweitere deinen Schein um die Segelprüfung.');
        $response->assertSee('149 €', false);
        $response->assertSee('134,10 €', false);
        $response->assertSee('SEGEL10');
        $response->assertSee('https://shop.example.test/segel-ergaenzung');
    }

    public function test_banner_is_hidden_without_a_configuration(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Mach weiter mit der Segelausbildung!');
    }

    public function test_banner_is_hidden_on_a_different_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $otherCourse = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->grantEntitlement($tenant, $learner, $otherCourse);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $otherCourse->id,
            'active' => true,
            'headline' => 'Nur auf dem anderen Kurs',
            'offer_url' => 'https://shop.example.test/x',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Nur auf dem anderen Kurs');
    }
}
