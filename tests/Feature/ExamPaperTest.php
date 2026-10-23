<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
use Illuminate\Support\Collection;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Prüfungsbögen: feste, wiederholbare Fragensets pro Bogen, im Unterschied
 * zur zufälligen Auswahl per ExamRuleSet/ExamBlueprint. Nutzt echte,
 * bereits importierte Fragen (kein erfundener Fragentext) auf einem
 * isolierten Test-Kurs, damit die geseedeten SBF-See-Bögen unangetastet
 * bleiben.
 */
class ExamPaperTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        // Bug-Review Testisolation: fehlte hier -- jeder per
        // createTenantUser() angelegte Nutzer blieb dauerhaft stehen, weil
        // nur der Tenant (nicht aber der Nutzer selbst) aufgeräumt wurde.
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_starting_a_paper_uses_its_fixed_questions_and_overview_reflects_the_result(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper, $questions] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $sessionQuestionIds, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestionIds = $session->questions()->orderBy('position')->pluck('question_id')->all();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestionIds, $sessionQuestions];
        });
        $response->assertRedirect(route('exam.show', $session));

        $this->assertSame($paper->id, $session->paper_id);
        $this->assertSame($questions->pluck('id')->all(), $sessionQuestionIds);

        foreach ($sessionQuestions as $sq) {
            $correctAnswer = $sq->revision->correctAnswer();
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $correctAnswer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $overview = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");
        $overview->assertOk();
        $overview->assertSee('100%');
        $overview->assertSee('2/2', false);
    }

    public function test_starting_the_same_paper_again_resumes_the_running_session_instead_of_duplicating(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        $count = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
        $this->assertSame(1, $count);
    }

    /**
     * Bug-Review B4: eine abgelaufene, aber noch "running" stehende Session
     * blockierte bisher den Neustart -- ein Klick auf "Jetzt starten" führte
     * (über show()) nur zum alten Ergebnis statt direkt zu einem neuen
     * Versuch. startPaper() wertet eine solche Session jetzt selbst sofort
     * aus und legt im selben Request einen frischen Versuch an.
     */
    public function test_starting_an_expired_paper_evaluates_the_stale_session_and_starts_a_fresh_one(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $staleSession = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        // Simuliert einen Lerner, der die laufende Prüfung einfach verlassen
        // hat, ohne sie manuell abzuschließen: das Zeitlimit (3600s, siehe
        // makeIsolatedExamPaper) ist längst überschritten.
        $this->onAdmin(fn () => ExamSession::where('id', $staleSession->id)->update(['started_at' => now()->subHours(2)]));

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        $sessions = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->get());
        $this->assertCount(2, $sessions);

        $staleSessionFresh = $sessions->firstWhere('id', $staleSession->id);
        $this->assertSame('evaluated', $staleSessionFresh->status);

        $newSession = $sessions->first(fn ($s) => $s->id !== $staleSession->id);
        $this->assertSame('running', $newSession->status);
        $response->assertRedirect(route('exam.show', $newSession));
    }

    public function test_completed_exam_appears_in_the_progress_overview(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper, $questions] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $sessionQuestions, $wrongQuestionIds] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();
            $wrongQuestionIds = $session->questions()->pluck('question_id');

            return [$session, $sessionQuestions, $wrongQuestionIds];
        });

        foreach ($sessionQuestions as $sq) {
            $wrongAnswer = $sq->revision->answers->firstWhere('is_correct', false);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $wrongAnswer->id,
            ]);
        }
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $progress = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/progress");

        $progress->assertOk();
        $progress->assertSee('Test Prüfungskurs');
        $progress->assertSee('Prüfungsbogen Nr. 1');
        $progress->assertSee('0%');
        $progress->assertSee('Nicht bestanden');

        // Jedes Ergebnis bietet "Erneut starten" (POST auf denselben Bogen),
        // "Falsche Fragen erneut lernen" (Smarttrainer eingeschränkt auf die
        // konkret falsch beantworteten Fragen dieser Prüfung) und "Ergebnisse".
        $progress->assertSee(route('exam.papers.start', ['course' => $course, 'paper' => $paper]));
        $progress->assertSee('Erneut starten');
        $progress->assertSee(route('learning.show', ['course' => $course, 'mode' => 'smarttrainer', 'questions' => $wrongQuestionIds->implode(',')]));
        $progress->assertSee('Falsche Fragen erneut lernen');
        $progress->assertSee(route('exam.result', $session));
        $progress->assertSee('Ergebnisse');
    }

    /** @return array{0: CourseDefinition, 1: ExamPaper, 2: Collection<int, ContentQuestion>} */
    private function makeIsolatedExamPaper(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PAPER-'.uniqid(),
                'name' => 'Test Prüfungskurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::where('code', 'SBF_BASIS')->firstOrFail();
            $questions = $module->questions()
                ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
                ->orderBy('content_question.content_id')
                ->take(2)
                ->get();

            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            $paper = ExamPaper::create(['course_id' => $course->id, 'paper_number' => 1, 'sort_order' => 1]);

            foreach ($questions as $i => $question) {
                ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $question->id, 'position' => $i + 1]);
            }

            return [$course, $paper, $questions];
        });
    }
}
