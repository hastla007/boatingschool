<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Favoriten sind sowohl kurs- als auch bereichsgebunden: Smart-Learning und
 * Prüfungsfragen haben pro Kurs jeweils ihre eigene Favoriten-/Fehler-
 * Auswertung, damit ein im Smart-Learning gespeicherter Favorit bzw. Fehler
 * nicht mit einem während der Prüfungssimulation entstandenen vermischt
 * wird -- beide Bereiche leben als Tabs auf einer gemeinsamen Seite, deren
 * beide Einstiegs-URLs (.../favorites/smart-learning, .../favorites/exam)
 * denselben Datensatz liefern und sich nur im aktiven Tab unterscheiden.
 */
class FavoritesPageTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_both_favorites_pages_require_entitlement_for_that_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning")->assertForbidden();
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam")->assertForbidden();
    }

    public function test_smart_learning_favorites_are_isolated_per_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $moduleA = $this->existingModule('SRC');
        [$questionA] = $this->anyPublishedQuestionIn($moduleA);

        $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$questionA->id}", ['context' => 'smart_learning'])
            ->assertRedirect();

        $favoritesA = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseA->id}/favorites/smart-learning");
        $favoritesB = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseB->id}/favorites/smart-learning");

        $favoritesA->assertOk();
        $favoritesB->assertOk();

        // Der Favorit gehört nur zu Kurs A -- Kurs B darf ihn nicht auflisten.
        $this->assertCount(1, $favoritesA->viewData('favorites'));
        $this->assertCount(0, $favoritesB->viewData('favorites'));
    }

    public function test_smart_learning_and_exam_favorites_are_kept_separate(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SRC');
        [$question] = $this->anyPublishedQuestionIn($module);

        // Dieselbe Frage wird ausschliesslich im Smart-Learning favorisiert ...
        $this->actingAsInTenant($learner, $tenant)
            ->post("/favorites/{$question->id}", ['context' => 'smart_learning'])
            ->assertRedirect();

        $smartLearning = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");
        $exam = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam");

        // ... und taucht deshalb nur dort auf, nicht bei den Prüfungsfragen.
        $this->assertCount(1, $smartLearning->viewData('favorites'));
        $this->assertCount(0, $exam->viewData('favorites'));
    }

    public function test_exam_favorites_wrong_answers_come_from_exam_sessions_not_smart_learning_attempts(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        foreach ($session->questions()->orderBy('position')->get() as $sq) {
            $wrongAnswer = $sq->revision->answers->firstWhere('is_correct', false);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $wrongAnswer->id,
            ]);
        }
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $exam = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam");
        $smartLearning = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");

        $exam->assertOk();
        $this->assertNotCount(0, $exam->viewData('wrongQuestions'));
        $this->assertCount(0, $smartLearning->viewData('wrongQuestions'));
    }

    public function test_the_kurse_navigation_lists_a_single_favoriten_link_for_each_entitled_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('favorites.smart-learning', $course));
        $response->assertSee('Favoriten');
    }

    public function test_the_combined_favorites_page_has_tabs_for_both_sections(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");

        $response->assertOk();
        $response->assertSee('Smart-Learning');
        $response->assertSee('Prüfungsfragen');
        $response->assertViewHas('activeTab', 'smart');

        $examEntry = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam");
        $examEntry->assertOk();
        $examEntry->assertViewHas('activeTab', 'exam');
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpVerifiedExamCourse(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $this->onAdmin(function () use ($course, $module, $reviewer) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);

            ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 3,
            ]);

            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        return [$tenant, $learner, $course];
    }
}
