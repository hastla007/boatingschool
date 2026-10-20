<?php

namespace Tests\Feature;

use App\Models\CoursePromoBanner;
use App\Models\Progress;
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

    private function masterAllQuestions($tenant, $learner, $course): void
    {
        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

            foreach ($questionIds as $questionId) {
                Progress::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $questionId],
                    ['learning_state' => 'gefestigt', 'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1]
                );
            }
        });
    }

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

    public function test_bullets_can_be_added_edited_and_cleared(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => [
                    'active' => '1',
                    'headline' => 'Test',
                    'bullets' => "Punkt 1\n\nPunkt 2\n",
                    'offer_url' => 'https://shop.example.test/x',
                ],
            ],
        ]);

        $banner = $this->onAdmin(fn () => CoursePromoBanner::where('tenant_id', $tenant->id)->where('course_id', $course->id)->first());
        $this->assertSame(['Punkt 1', 'Punkt 2'], $banner->bullets);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/course-promo-banners', [
            'banners' => [
                $course->id => [
                    'active' => '1',
                    'headline' => 'Test',
                    'bullets' => '',
                    'offer_url' => 'https://shop.example.test/x',
                ],
            ],
        ]);

        $refetched = $this->onAdmin(fn () => CoursePromoBanner::where('tenant_id', $tenant->id)->where('course_id', $course->id)->first());
        $this->assertSame([], $refetched->bullets);
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

    public function test_banner_with_bullets_shows_them_on_the_course_page(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Test mit Bullets',
            'bullets' => ['Erster Punkt', 'Zweiter Punkt'],
            'offer_url' => 'https://shop.example.test/x',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Erster Punkt');
        $response->assertSee('Zweiter Punkt');
    }

    public function test_banner_is_hidden_below_its_progress_trigger_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'trigger_course_id' => $course->id,
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Erst ab 50%');
    }

    public function test_banner_is_shown_once_its_progress_trigger_threshold_is_reached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->masterAllQuestions($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'trigger_course_id' => $course->id,
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Erst ab 50%');
    }

    public function test_banner_trigger_can_reference_a_different_course_than_it_is_shown_on(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $otherCourse = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->grantEntitlement($tenant, $learner, $otherCourse);
        $this->masterAllQuestions($tenant, $learner, $otherCourse);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'active' => true,
            'headline' => 'Erst ab Fortschritt in UBI',
            'offer_url' => 'https://shop.example.test/x',
            'trigger_course_id' => $otherCourse->id,
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Erst ab Fortschritt in UBI');
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
