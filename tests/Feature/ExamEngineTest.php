<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamRuleSet;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Abnahmekriterien: "Während der laufenden Prüfung erscheint kein
 * Richtig/Falsch-Feedback" und "Eine Prüfung folgt exakt ihrer
 * freigegebenen Regelversion". Nutzt einen eigenen, isoliert verifizierten
 * Rule Set auf dem bestehenden globalen SRC-Kurs, um den geseedeten
 * SBF-See-Datensatz nicht zu verändern.
 */
class ExamEngineTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        // Reihenfolge: erst Tenants (kaskadiert exam_session weg), dann das
        // Rule Set (referenziert per FK auf module/course), erst danach die
        // Nutzer (exam_rule_set.verified_by zeigt sonst noch auf sie).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_exam_answer_response_never_reveals_correctness_before_finish(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = \App\Models\ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        $question = $session->questions()->orderBy('position')->firstOrFail();
        $answer = $question->revision->answers->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);

        $response->assertRedirect(route('exam.show', $session));
        $this->assertStringNotContainsString('correct', strtolower($response->headers->get('location')));

        $this->assertDatabaseHas('exam_session_question', [
            'exam_session_id' => $session->id,
            'position' => $question->position,
            'correct' => null,
        ]);
    }

    public function test_finishing_computes_score_from_stored_answers_and_locks_the_session(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = \App\Models\ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        foreach ($session->questions()->orderBy('position')->get() as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
        $response->assertRedirect(route('exam.result', $session));

        $session->refresh();
        $this->assertSame('evaluated', $session->status);
        $this->assertEquals(1.0, $session->score);
        $this->assertTrue($session->passed);
    }

    public function test_random_exams_without_a_paper_are_numbered_sequentially_on_the_progress_page(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->finishRandomExam($tenant, $learner, $course);
        $this->finishRandomExam($tenant, $learner, $course);

        $progress = $this->actingAsInTenant($learner, $tenant)->get('/progress');

        $progress->assertOk();
        $progress->assertSee('Prüfungsbogen Nr. 1');
        $progress->assertSee('Prüfungsbogen Nr. 2');
    }

    private function finishRandomExam(\App\Models\Tenant $tenant, User $learner, CourseDefinition $course): void
    {
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = \App\Models\ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('status', 'running')->firstOrFail();

        foreach ($session->questions()->orderBy('position')->get() as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
    }

    /** @return array{0: \App\Models\Tenant, 1: User, 2: CourseDefinition} */
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

            \App\Models\ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 3,
            ]);

            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        return [$tenant, $learner, $course];
    }
}
