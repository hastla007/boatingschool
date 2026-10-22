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
 * Bug-Review B2: "Meine Fehler (Prüfung)" ermittelte Falschantworten per
 * Collection::where('correct', false). Diese Methode vergleicht standardmäßig
 * lose -- bei einer noch laufenden Prüfung ist correct für unbeantwortete
 * Fragen NULL, und PHP wertet null == false als wahr. Unbeantwortete Fragen
 * einer laufenden Prüfung erschienen dadurch fälschlich als "Fehler", noch
 * bevor der Lerner überhaupt geantwortet hat.
 */
class ExamFavoritesMisgradingTest extends TestCase
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

    public function test_unanswered_questions_of_a_running_exam_do_not_count_as_mistakes(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('status', 'running')->firstOrFail());

        $this->asTenant($tenant, function () use ($session) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'correct' => null,
            ]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam");

        $response->assertOk();
        $response->assertViewHas('examWrongQuestions', fn ($questions) => $questions->isEmpty());
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
