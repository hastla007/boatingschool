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
 * Bug-Review B2 (Folgefehler): ExamEvaluationService setzt correct=false
 * auch für nie beantwortete Fragen -- die Ergebnisseite zeigte sie deshalb
 * in der "Fehlerliste" genau wie tatsächlich falsch beantwortete Fragen,
 * obwohl answered_at bei ihnen NULL ist. Die Ergebnisseite trennt die beiden
 * Fälle jetzt in "Fehlerliste" (answered_at gesetzt) und "Nicht beantwortet"
 * (answered_at NULL).
 */
class ExamResultUnansweredTest extends TestCase
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

    public function test_an_unanswered_question_is_shown_separately_from_wrongly_answered_ones(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        // Erste Frage: bewusst falsch beantworten. Zweite Frage: gar nicht
        // beantworten (bleibt answered_at = NULL). Restliche Fragen: korrekt.
        $wrongQuestion = $sessionQuestions[0];
        $skippedQuestion = $sessionQuestions[1];
        $wrongAnswer = $wrongQuestion->revision->answers->firstWhere('is_correct', false);
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $wrongQuestion->position,
            'answer_id' => $wrongAnswer->id,
        ]);

        foreach ($sessionQuestions->skip(2) as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        // finish() wertet automatisch aus, sobald alle Fragen eine
        // Eintragung haben -- die übersprungene Frage bleibt hier aber
        // absichtlich ohne jede Antwort, also muss die Session manuell
        // beendet werden, genau wie ein Nutzer, der vorzeitig abgibt.
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish");

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('exam.result', $session));
        $response->assertOk();

        $response->assertSee($wrongQuestion->revision->question_text);
        $response->assertSee($skippedQuestion->revision->question_text);
        $response->assertSee('Fehlerliste');
        $response->assertSee('Nicht beantwortet');

        $this->asTenant($tenant, function () use ($session, $skippedQuestion) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $skippedQuestion->position,
                'correct' => false,
                'answered_at' => null,
            ]);
        });
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpVerifiedExamCourse(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SKN');
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
