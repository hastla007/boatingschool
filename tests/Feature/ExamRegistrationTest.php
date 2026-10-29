<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\CourseExamRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExamRegistration;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Prüfungsanmeldung je Bootsschule und Kurs: Standard ist das Termintool
 * "Praxis & Prüfung" (die Bootsschule meldet an); stellt die Bootsschule
 * einen Kurs auf Selbstanmeldung, sehen Teilnehmer stattdessen "Prüfung
 * buchen". Kurse ohne Seite zur Selbstanmeldung bleiben beim Termintool.
 */
class ExamRegistrationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_by_default_the_school_registers_and_learners_see_the_appointment_tool(): void
    {
        [$tenant, , $learner, $course] = $this->schoolWithLearner('SRC-UBI');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")
            ->assertOk()->assertSee('Praxis &amp; Prüfung', false)->assertDontSee('Prüfung buchen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/pruefung-buchen")->assertNotFound();
    }

    public function test_a_school_can_let_learners_register_themselves_per_course(): void
    {
        [$tenant, $admin, $learner, $combo] = $this->schoolWithLearner('SRC-UBI');
        $src = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $src);

        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=courses')
            ->assertOk()->assertSee('Teilnehmer meldet sich selbst an')->assertSee('Anmeldung durch die Bootsschule');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allCourseIds(),
            'registration' => [$combo->id => 'self', $src->id => 'school'],
        ])->assertRedirect();

        $this->onAdmin(function () use ($tenant, $combo, $src) {
            $this->assertDatabaseHas('course_exam_registration', ['tenant_id' => $tenant->id, 'course_id' => $combo->id, 'mode' => 'self']);
            $this->assertDatabaseMissing('course_exam_registration', ['tenant_id' => $tenant->id, 'course_id' => $src->id]);
        });

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}")
            ->assertOk()->assertSee('Prüfung buchen')->assertDontSee('Praxis &amp; Prüfung', false);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/pruefung-buchen")->assertOk()->assertSee('SRC-Prüfung buchen');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/praxis-pruefung")->assertRedirect("/courses/{$combo->id}/pruefung-buchen");
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/praxis-pruefung")->assertNotFound();

        // Der andere Kurs derselben Bootsschule bleibt beim Termintool.
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$src->id}")->assertOk()->assertDontSee('Prüfung buchen');

        // Zurück auf "Bootsschule meldet an".
        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', ['enabled' => $this->allCourseIds()])->assertRedirect();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/pruefung-buchen")->assertNotFound();
    }

    public function test_courses_without_a_self_registration_page_stay_with_the_school(): void
    {
        [$tenant, $admin] = $this->schoolWithLearner('SBF-SEE');
        $own = new CourseDefinition(['code' => 'EIGENER-KURS']);

        $this->assertFalse(ExamRegistration::hasSelfPage($own));
        $this->assertSame(ExamRegistration::SCHOOL, ExamRegistration::modeFor($tenant, $own));

        $sbf = $this->existingCourse('SBF-SEE');
        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', ['enabled' => $this->allCourseIds(), 'registration' => [$sbf->id => 'irgendwas']])
            ->assertSessionHasErrors('registration.'.$sbf->id);
    }

    public function test_the_setting_is_isolated_per_school(): void
    {
        [$tenant, , , $combo] = $this->schoolWithLearner('SRC-UBI');
        [$other, , $otherLearner] = $this->schoolWithLearner('SRC-UBI');
        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $combo->id, 'mode' => 'self']));

        $this->actingAsInTenant($otherLearner, $other)->get("/courses/{$combo->id}/pruefung-buchen")->assertNotFound();
        $this->assertSame(0, $this->asTenant($other, fn () => CourseExamRegistration::count()));
    }

    /** @return array{0: Tenant, 1: User, 2: User, 3: CourseDefinition} */
    private function schoolWithLearner(string $code): array
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse($code);
        $this->grantEntitlement($tenant, $learner, $course);

        return [$tenant, $admin, $learner, $course];
    }

    /** @return list<string> */
    private function allCourseIds(): array
    {
        return $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)->pluck('id')->all());
    }
}
