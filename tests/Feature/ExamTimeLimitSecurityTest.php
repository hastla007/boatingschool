<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
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
        [$session, $question] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();

            return [$session, $question];
        });

        // Zeitlimit künstlich in die Vergangenheit verlegen statt zu warten.
        $this->onAdmin(fn () => ExamSession::where('id', $session->id)->update(['started_at' => now()->subMinutes(5)]));

        $answer = $question->revision->answers->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);

        $response->assertStatus(409);
        $this->asTenant($tenant, function () use ($session, $question) {
            $this->assertSame('evaluated', ExamSession::find($session->id)->status);
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $question->position,
                'answered_at' => null,
            ]);
        });
    }

    public function test_an_already_answered_question_cannot_be_answered_again(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 3600);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $question] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();

            return [$session, $question];
        });
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
        $this->asTenant($tenant, function () use ($session, $question, $firstAnswer) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $question->position,
                'selected_answer_id' => $firstAnswer->id,
            ]);
        });
    }

    public function test_an_answer_saved_after_the_deadline_does_not_count_toward_the_score(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 60);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $questions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $questions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $questions];
        });

        // Simuliert eine Antwort, die (z. B. durch eine Race Condition) trotz
        // allem nach Ablauf der Frist gespeichert wurde.
        //
        // Statt $q->update(...)/$session->update(...): eine zuvor geladene
        // Model-Instanz trägt die Connection fest, die beim Laden "default"
        // war (hier: die App-Rolle) -- onAdmin() schaltet nur die
        // KONFIGURATION um, ein Aufruf auf dieser Instanz würde also
        // trotzdem (unter RLS, ohne gesetzten Tenant-Kontext) ins Leere
        // laufen und 0 Zeilen treffen, obwohl update()/save() trotzdem true
        // zurückgibt. Der statische Query-Builder-Stil baut dagegen bei
        // jedem Aufruf neu auf der gerade aktuellen Default-Verbindung auf.
        $this->onAdmin(function () use ($session, $questions) {
            $q = $questions->first();
            $correctAnswerId = $q->revision->correctAnswer()->id;
            ExamSessionQuestion::where('exam_session_id', $q->exam_session_id)->where('question_id', $q->question_id)->update([
                'selected_answer_id' => $correctAnswerId,
                'answered_at' => $session->started_at->copy()->addMinutes(10),
            ]);
            ExamSession::where('id', $session->id)->update(['started_at' => now()->subMinutes(15)]);
        });

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish");

        $this->asTenant($tenant, function () use ($session, $questions) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $questions->first()->position,
                'correct' => false,
            ]);
        });
    }

    public function test_a_question_in_a_running_exam_cannot_be_answered_via_the_learning_mode(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse(timeLimitSeconds: 3600);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $question = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();
        });

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $question->revision_id,
            'answer_id' => $question->revision->answers->first()->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertStatus(409);
        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertDatabaseMissing('attempt', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->question_id,
            ]);
        });
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpVerifiedExamCourse(int $timeLimitSeconds = 3600): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('ADD-UBI-TO-SRC');
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
