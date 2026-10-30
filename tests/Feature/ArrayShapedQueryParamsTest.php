<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\CourseExamRegistration;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use App\Support\ExamRegistration;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Array-Query-Parameter): Laravel liefert ?x[]=a als Array --
 * mehrere Stellen reichten solche Werte ungeprüft an ?string-Parameter,
 * trim() oder einen (string)-Cast weiter und endeten mit HTTP 500. Ein
 * nicht-string-förmiger Wert muss wie ein fehlender Parameter behandelt
 * werden. Außerdem: ein nicht-UUID-förmiger kapitel-Filter auf der
 * Auswertungsseite des Video-Quiz (invalid input syntax for type uuid).
 */
class ArrayShapedQueryParamsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_learning_ignores_topic_and_module_submitted_as_arrays(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=topic&topic[]=foo")->assertOk();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?module[]=foo")->assertOk();
    }

    public function test_funk_pages_ignore_teil_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/muendlich?teil[]=UBI")->assertOk();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/praxis?teil[]=UBI")->assertOk();
    }

    public function test_exam_booking_ignores_teil_submitted_as_an_array(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => ExamRegistration::SELF]));

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/pruefung-buchen?teil[]=UBI")
            ->assertOk()->assertSee('SRC-Prüfung buchen');
    }

    public function test_admin_dashboard_ignores_coupon_and_anmeldung_filters_submitted_as_arrays(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=coupons&coupon_search[]=foo')->assertOk();
        $this->actingAsInTenant($owner, $tenant)
            ->get('/admin?tab=praxis-anmeldungen&anmeldung_search[]=foo&anmeldung_from[]=2026-01-01&anmeldung_to[]=2026-12-31')
            ->assertOk();
    }

    public function test_video_quiz_result_ignores_a_non_uuid_kapitel_filter(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        [$course, $lesson] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-VQ-KAPITEL-'.uniqid(),
                'name' => 'Test Videokurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel', 'sort_order' => 1]);
            $lesson = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Lektion 1', 'video_url' => 'https://example.test/1.mp4', 'sort_order' => 1]);

            // Eine echte Frage aus dem Katalog genügt -- result() braucht
            // mindestens eine Frage, sonst antwortet es ohnehin mit 404.
            [$question] = $this->anyPublishedQuestionIn($this->existingModule('SBF_SEE'));
            $lesson->questions()->attach($question->id, ['sort_order' => 1]);

            return [$course, $lesson];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/video/{$lesson->id}/questions/result?kapitel=foo")
            ->assertOk();
    }
}
