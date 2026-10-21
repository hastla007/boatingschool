<?php

namespace Tests\Feature;

use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M2: answer() prüfte bisher nur status === 'running',
 * nicht ob die Prüfungszeit schon abgelaufen ist -- eine Session blieb
 * "running", bis show() oder finish() aufgerufen wurden. Außerdem ließ
 * sich dieselbe Frage beliebig oft umantworten, und ein Lern-Attempt für
 * eine gerade in der Prüfung offene Frage verriet die richtige Antwort.
 */
class ExamTimeLimitSecurityTest extends TestCase
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

    public function test_answering_after_the_time_limit_is_rejected_and_the_session_is_auto_finished(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 60);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        // Zeitlimit künstlich in die Vergangenheit verlegen statt zu warten.
        $this->onAdmin(fn () => $session->update(['started_at' => now()->subMinutes(5)]));

        $question = $session->questions()->orderBy('position')->firstOrFail();
        $answer = $question->revision->answers->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);

        $response->assertStatus(409);
        $this->assertSame('evaluated', $session->fresh()->status);
        $this->assertDatabaseHas('exam_session_question', [
            'exam_session_id' => $session->id,
            'position' => $question->position,
            'answered_at' => null,
        ]);
    }

    public function test_an_already_answered_question_cannot_be_answered_again(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 3600);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        $question = $session->questions()->orderBy('position')->firstOrFail();
        $answers = $question->revision->answers;
        $firstAnswer = $answers->first();
        $otherAnswer = $answers->firstWhere('id', '!=', $firstAnswer->id) ?? $firstAnswer;

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $firstAnswer->id,
        ])->assertRedirect();

        $retry = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $otherAnswer->id,
        ]);

        $retry->assertNotFound();
        $this->assertDatabaseHas('exam_session_question', [
            'exam_session_id' => $session->id,
            'position' => $question->position,
            'selected_answer_id' => $firstAnswer->id,
        ]);
    }

    public function test_an_answer_saved_after_the_deadline_does_not_count_toward_the_score(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 60);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
        $questions = $session->questions()->orderBy('position')->get();

        // Simuliert eine Antwort, die (z. B. durch eine Race Condition) trotz
        // allem nach Ablauf der Frist gespeichert wurde.
        $this->onAdmin(function () use ($session, $questions) {
            $q = $questions->first();
            $correctAnswerId = $q->revision->correctAnswer()->id;
            $q->update([
                'selected_answer_id' => $correctAnswerId,
                'answered_at' => $session->started_at->copy()->addMinutes(10),
            ]);
            $session->update(['started_at' => now()->subMinutes(15)]);
        });

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish");

        $this->assertDatabaseHas('exam_session_question', [
            'exam_session_id' => $session->id,
            'position' => $questions->first()->position,
            'correct' => false,
        ]);
    }

    public function test_a_question_in_a_running_exam_cannot_be_answered_via_the_learning_mode(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 3600);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
        $question = $session->questions()->orderBy('position')->firstOrFail();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $question->revision_id,
            'answer_id' => $question->revision->answers->first()->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseMissing('attempt', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $question->question_id,
        ]);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: \App\Models\CourseDefinition} */
    private function setUpVerifiedExamCourse(int $timeLimitSeconds = 3600): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $this->onAdmin(function () use ($course, $module, $reviewer, $timeLimitSeconds) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => $timeLimitSeconds,
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
