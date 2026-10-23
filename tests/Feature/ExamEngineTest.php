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
        [$session, $question] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();

            return [$session, $question];
        });
        $answer = $question->revision->answers->first();

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);

        $response->assertRedirect(route('exam.show', $session));
        $this->assertStringNotContainsString('correct', strtolower($response->headers->get('location')));

        $this->asTenant($tenant, function () use ($session, $question) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $question->position,
                'correct' => null,
            ]);
        });
    }

    public function test_finishing_computes_score_from_stored_answers_and_locks_the_session(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        foreach ($sessionQuestions as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
        $response->assertRedirect(route('exam.result', $session));

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('evaluated', $session->status);
        $this->assertEquals(1.0, $session->score);
        $this->assertTrue($session->passed);
    }

    public function test_random_exams_without_a_paper_are_numbered_sequentially_on_the_progress_page(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->finishRandomExam($tenant, $learner, $course);
        $this->finishRandomExam($tenant, $learner, $course);

        $progress = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/progress");

        $progress->assertOk();
        $progress->assertSee('Prüfungsbogen Nr. 1');
        $progress->assertSee('Prüfungsbogen Nr. 2');
    }

    /**
     * Bug-Review N20: derselbe Doppelklick-Fehler wie B4 (startPaper()),
     * nur für die zufällig zusammengestellte Prüfung ohne Bogen -- dafür
     * gab es bisher weder einen Advisory-Lock noch einen Unique-Index.
     */
    public function test_starting_a_random_exam_again_resumes_the_running_session_instead_of_duplicating(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");

        $count = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
        $this->assertSame(1, $count);
    }

    /**
     * Bug-Review N20 (Folgefehler): eine abgelaufene, aber noch "running"
     * stehende Session blockierte bisher den Neustart -- analog zu B4 bei
     * startPaper(). start() wertet eine solche Session jetzt selbst sofort
     * aus und legt im selben Request einen frischen Versuch an.
     */
    public function test_starting_a_random_exam_after_it_expired_evaluates_the_stale_session_and_starts_a_fresh_one(): void
    {
        [$tenant, $learner, $course] = $this->setUpVerifiedExamCourse();

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $staleSession = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        // Zeitlimit (3600s, siehe setUpVerifiedExamCourse) ist längst
        // überschritten -- simuliert einen Lerner, der die laufende
        // Prüfung einfach verlassen hat, ohne sie manuell abzuschließen.
        $this->onAdmin(fn () => ExamSession::where('id', $staleSession->id)->update(['started_at' => now()->subHours(2)]));

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");

        $sessions = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->get());
        $this->assertCount(2, $sessions);

        $staleSessionFresh = $sessions->firstWhere('id', $staleSession->id);
        $this->assertSame('evaluated', $staleSessionFresh->status);

        $newSession = $sessions->first(fn ($s) => $s->id !== $staleSession->id);
        $this->assertSame('running', $newSession->status);
        $response->assertRedirect(route('exam.show', $newSession));
    }

    private function finishRandomExam(Tenant $tenant, User $learner, CourseDefinition $course): void
    {
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                ->where('status', 'running')->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        foreach ($sessionQuestions as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");
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
