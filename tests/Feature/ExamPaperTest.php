<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
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

        parent::tearDown();
    }

    public function test_starting_a_paper_uses_its_fixed_questions_and_overview_reflects_the_result(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper, $questions] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
        $response->assertRedirect(route('exam.show', $session));

        $this->assertSame($paper->id, $session->paper_id);
        $sessionQuestionIds = $session->questions()->orderBy('position')->pluck('question_id')->all();
        $this->assertSame($questions->pluck('id')->all(), $sessionQuestionIds);

        foreach ($session->questions()->orderBy('position')->get() as $sq) {
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

        $this->assertSame(1, ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
    }

    public function test_completed_exam_appears_in_the_progress_overview(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $paper, $questions] = $this->makeIsolatedExamPaper();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        foreach ($session->questions()->orderBy('position')->get() as $sq) {
            $wrongAnswer = $sq->revision->answers->firstWhere('is_correct', false);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $wrongAnswer->id,
            ]);
        }
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $progress = $this->actingAsInTenant($learner, $tenant)->get('/progress');

        $progress->assertOk();
        $progress->assertSee('Test Prüfungskurs');
        $progress->assertSee('Bogen 1');
        $progress->assertSee('0%');
        $progress->assertSee('Nicht bestanden');
    }

    /** @return array{0: CourseDefinition, 1: ExamPaper, 2: \Illuminate\Support\Collection<int, ContentQuestion>} */
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
