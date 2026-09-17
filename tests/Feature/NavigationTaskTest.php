<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class NavigationTaskTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert keine Navigationsdaten weg, die sind
        // zentraler Content), dann den isolierten Test-Kurs (kaskadiert
        // navigation_task/navigation_question weg).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_learner_without_entitlement_cannot_view_navigation_tasks(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedNavigationTask();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation");

        $response->assertForbidden();
    }

    public function test_navigation_task_page_shows_scenario_and_questions_with_answers(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedNavigationTask();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation");

        $response->assertOk();
        $response->assertSee('Testszenario für die Navigationsaufgabe.');
        $response->assertSee('Wie lautet der Testkurs?');
        $response->assertSee('Musterlösung:');
        $response->assertSee('090°');
    }

    /** @return array{0: CourseDefinition, 1: NavigationTask} */
    private function makeIsolatedNavigationTask(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-NAV-'.uniqid(),
                'name' => 'Test Navigationskurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $task = NavigationTask::create([
                'course_id' => $course->id,
                'task_number' => 1,
                'scenario_text' => 'Testszenario für die Navigationsaufgabe.',
                'sort_order' => 1,
            ]);

            NavigationQuestion::create([
                'navigation_task_id' => $task->id,
                'question_number' => 1,
                'question_text' => 'Wie lautet der Testkurs?',
                'answer_text' => '090°',
                'sort_order' => 1,
            ]);

            return [$course, $task];
        });
    }
}
