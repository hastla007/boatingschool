<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Z8: intro() zeigt für einen Kurs mit echten Prüfungsbögen
 * (examPapers) ausschließlich papersOverview() -- exam/intro.blade.php mit
 * dem Formular auf die zufällig zusammengestellte Prüfung (start()) wird
 * für einen solchen Kurs nie gerendert, die Route blieb aber live
 * erreichbar und griff bei SRC/FKN auf ein veraltetes ExamRuleSet-Blueprint
 * mit nur 10 statt der echten 24 (SRC) bzw. 15 (FKN) Fragen pro Bogen
 * zurück.
 */
class RandomExamBlockedForCourseWithPapersTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_random_exam_route_is_blocked_for_a_course_with_real_exam_papers(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");

        $response->assertStatus(409);
    }

    public function test_the_random_exam_route_is_blocked_for_fkn_as_well(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");

        $response->assertStatus(409);
    }
}
