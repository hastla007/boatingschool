<?php

namespace Tests\Feature;

use App\Models\CoursePromoBanner;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschulen können frei formulierte Banner anlegen, die für einen
 * anderen/weiterführenden Kurs werben -- anders als der vorgefertigte
 * Addon-Banner (siehe TenantAddonTest) gehört der Text hier komplett der
 * Bootsschule. Ein Banner kann gleichzeitig auf mehreren Kursseiten
 * erscheinen (course_ids), auf jeder davon gated durch den Fortschritt
 * des jeweiligen Kurses selbst. Siehe admin/tabs/course-promo-banners.
 * blade.php und CoursePromoBannerController.
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

    public function test_a_school_admin_can_create_a_promo_banner(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/course-promo-banners', [
            'active' => '1',
            'headline' => 'Mach weiter mit der Segelausbildung!',
            'description' => 'Erweitere deinen Schein um die Segelprüfung.',
            'bullets' => "Punkt 1\n\nPunkt 2\n",
            'cta_label' => 'Jetzt entdecken',
            'price_label' => '149 €',
            'discount_percent' => '10',
            'coupon_code' => 'SEGEL10',
            'offer_url' => 'https://shop.example.test/segel-ergaenzung',
            'course_ids' => [$course->id],
            'trigger_percent' => '0',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('course_promo_banner', [
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Mach weiter mit der Segelausbildung!',
            'description' => 'Erweitere deinen Schein um die Segelprüfung.',
            'cta_label' => 'Jetzt entdecken',
            'price_label' => '149 €',
            'discount_percent' => 10,
            'coupon_code' => 'SEGEL10',
            'offer_url' => 'https://shop.example.test/segel-ergaenzung',
        ]);

        $banner = $this->onAdmin(fn () => CoursePromoBanner::where('tenant_id', $tenant->id)->first());
        $this->assertSame(['Punkt 1', 'Punkt 2'], $banner->bullets);
        $this->assertSame([$course->id], $banner->course_ids);
    }

    public function test_a_banner_can_target_multiple_courses_at_once(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');

        $this->actingAsInTenant($admin, $tenant)->post('/admin/course-promo-banners', [
            'active' => '1',
            'headline' => 'Für beide Kurse',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$courseA->id, $courseB->id],
        ]);

        $banner = $this->onAdmin(fn () => CoursePromoBanner::where('tenant_id', $tenant->id)->first());
        $this->assertEqualsCanonicalizing([$courseA->id, $courseB->id], $banner->course_ids);
    }

    public function test_a_school_admin_can_update_a_promo_banner(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $banner = $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Alt',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/course-promo-banners/{$banner->id}", [
            'active' => '1',
            'headline' => 'Neu',
            'offer_url' => 'https://shop.example.test/y',
            'course_ids' => [$course->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('course_promo_banner', ['id' => $banner->id, 'headline' => 'Neu', 'offer_url' => 'https://shop.example.test/y']);
    }

    public function test_a_school_admin_can_delete_a_promo_banner(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $banner = $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Löschen',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->delete("/admin/course-promo-banners/{$banner->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('course_promo_banner', ['id' => $banner->id]);
    }

    public function test_a_school_admin_cannot_update_another_tenants_banner(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $course = $this->existingCourse('SRC');
        $banner = $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenantA->id,
            'active' => true,
            'headline' => 'Nur A',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->patch("/admin/course-promo-banners/{$banner->id}", [
            'active' => '1',
            'headline' => 'Übernommen',
            'offer_url' => 'https://shop.example.test/y',
        ]);

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertDatabaseHas('course_promo_banner', ['id' => $banner->id, 'headline' => 'Nur A']));
    }

    public function test_course_promo_banners_tab_lists_existing_banners(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'E2E Sichtbarer Banner',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=course-promo-banners');

        $response->assertOk();
        $response->assertSee('E2E Sichtbarer Banner');
    }

    public function test_banner_configuration_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenantA->id,
            'active' => true,
            'headline' => 'Nur für A',
            'offer_url' => 'https://shop.example.test/a',
            'course_ids' => [$course->id],
        ]));

        $this->onAdmin(fn () => $this->assertDatabaseHas('course_promo_banner', ['tenant_id' => $tenantA->id, 'headline' => 'Nur für A']));
        $this->onAdmin(fn () => $this->assertDatabaseMissing('course_promo_banner', ['tenant_id' => $tenantB->id, 'headline' => 'Nur für A']));
    }

    public function test_banner_is_shown_on_each_of_its_configured_courses(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Auf beiden Kursen sichtbar',
            'description' => 'Erweitere deinen Schein um die Segelprüfung.',
            'bullets' => ['Erster Punkt', 'Zweiter Punkt'],
            'cta_label' => 'Jetzt entdecken',
            'price_label' => '149 €',
            'discount_percent' => 10,
            'coupon_code' => 'SEGEL10',
            'offer_url' => 'https://shop.example.test/segel-ergaenzung',
            'course_ids' => [$courseA->id, $courseB->id],
        ]));

        $responseA = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseA->id}");
        $responseA->assertOk();
        $responseA->assertSee('Auf beiden Kursen sichtbar');
        $responseA->assertSee('Erster Punkt');
        $responseA->assertSee('149 €', false);
        $responseA->assertSee('134,10 €', false);
        $responseA->assertSee('SEGEL10');
        $responseA->assertSee('https://shop.example.test/segel-ergaenzung');

        $responseB = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseB->id}");
        $responseB->assertOk();
        $responseB->assertSee('Auf beiden Kursen sichtbar');
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

    public function test_banner_is_hidden_on_a_course_it_does_not_target(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $otherCourse = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->grantEntitlement($tenant, $learner, $otherCourse);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Nur auf dem anderen Kurs',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$otherCourse->id],
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Nur auf dem anderen Kurs');
    }

    public function test_banner_is_hidden_below_its_progress_trigger_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
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
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$course->id],
            'trigger_percent' => 50,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Erst ab 50%');
    }

    public function test_banner_trigger_threshold_is_evaluated_per_course_independently(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);
        $this->masterAllQuestions($tenant, $learner, $courseA);

        $this->onAdmin(fn () => CoursePromoBanner::create([
            'tenant_id' => $tenant->id,
            'active' => true,
            'headline' => 'Erst ab 50%',
            'offer_url' => 'https://shop.example.test/x',
            'course_ids' => [$courseA->id, $courseB->id],
            'trigger_percent' => 50,
        ]));

        $responseA = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseA->id}");
        $responseA->assertOk();
        $responseA->assertSee('Erst ab 50%');

        $responseB = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseB->id}");
        $responseB->assertOk();
        $responseB->assertDontSee('Erst ab 50%');
    }
}
