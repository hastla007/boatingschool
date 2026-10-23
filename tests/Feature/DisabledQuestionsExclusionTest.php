<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\Module;
use App\Models\Progress;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B12: eine im Superadmin deaktivierte Frage
 * (content_question.active = false) wurde trotzdem weiter ausgespielt --
 * sowohl im Smarttrainer (SmarttrainerService::nextQuestion) als auch bei
 * der zufälligen Zusammenstellung einer Prüfung ohne festen Bogen
 * (ExamController::start). Beide Stellen filtern jetzt auf active=true.
 */
class DisabledQuestionsExclusionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

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
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        // Bug-Review Testisolation: fehlte hier -- jeder per
        // createTenantUser() angelegte Nutzer blieb dauerhaft stehen, weil
        // nur der Tenant (nicht aber der Nutzer selbst) aufgeräumt wurde.
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_the_smarttrainer_never_serves_a_disabled_question(): void
    {
        [$course, $module, $activeQuestion, $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        // Priorisierung laut SmarttrainerService: "noch nie gesehen" erhält
        // den höchsten Score (1000). Ohne den active-Filter würde die
        // deaktivierte (nie gesehene) Frage die bereits einmal bearbeitete
        // aktive Frage daher ausstechen -- diese Progress-Zeile deckt genau
        // diesen Fall auf.
        $this->onAdmin(fn () => Progress::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $activeQuestion->id,
            'attempt_count' => 1,
            'correct_count' => 1,
            'incorrect_count' => 0,
            'current_streak' => 1,
            'mastery_score' => 0.2,
            'learning_state' => 'in_bearbeitung',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_correct_at' => now(),
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn");

        $response->assertOk();
        $response->assertViewHas('revision', fn ($revision) => $revision->question_id === $activeQuestion->id);
    }

    public function test_a_random_exam_never_includes_a_disabled_question(): void
    {
        [$course, $module, $activeQuestion, $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($course, $module) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 2,
            ]);
        });

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $sessionQuestionIds = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return $session->questions()->pluck('question_id')->all();
        });
        $this->assertSame([$activeQuestion->id], $sessionQuestionIds);
    }

    public function test_a_fixed_exam_paper_never_includes_a_disabled_question(): void
    {
        [$course, $module, $activeQuestion, $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $paper = $this->onAdmin(function () use ($course, $module, $activeQuestion, $disabledQuestion) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;
            ExamBlueprint::create(['rule_set_id' => $ruleSet->id, 'module_id' => $module->id, 'question_count' => 2]);

            $paper = ExamPaper::create(['course_id' => $course->id, 'paper_number' => 1, 'sort_order' => 1]);
            ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $activeQuestion->id, 'position' => 1]);
            ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $disabledQuestion->id, 'position' => 2]);

            return $paper;
        });

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");
        $sessionQuestionIds = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return $session->questions()->orderBy('position')->pluck('question_id')->all();
        });

        $this->assertSame([$activeQuestion->id], $sessionQuestionIds);
    }

    public function test_favorites_page_excludes_a_disabled_question(): void
    {
        [$course, $module, $activeQuestion, $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $activeQuestion, $disabledQuestion) {
            foreach ([$activeQuestion, $disabledQuestion] as $question) {
                Favorite::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'question_id' => $question->id,
                    'context' => Favorite::CONTEXT_SMART_LEARNING,
                ]);
            }
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");

        $response->assertOk();
        $response->assertSee('Aktive Testfrage B12');
        $response->assertDontSee('Deaktivierte Testfrage B12');
    }

    /**
     * Bug-Review B12 (Folgefehler): storeAttempt() prüfte bisher nur
     * editorial_status, nicht ob die Frage selbst (content_question.active)
     * deaktiviert wurde -- eine direkt per revision_id eingereichte
     * deaktivierte Frage (sie ist dem Lerner also schon vorher bekannt,
     * sonst hätte er die revision_id nicht) wurde trotzdem angenommen.
     */
    public function test_store_attempt_rejects_a_disabled_question_submitted_directly(): void
    {
        [$course, , , $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $revision = $this->onAdmin(fn () => $disabledQuestion->publishedRevision()->load('answers'));
        $answer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $answer->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertNotFound();
        $this->onAdmin(function () use ($tenant, $learner, $disabledQuestion) {
            $this->assertDatabaseMissing('attempt', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $disabledQuestion->id,
            ]);
        });
    }

    public function test_video_quiz_excludes_a_disabled_question(): void
    {
        [$course, , $activeQuestion, $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $lesson = $this->onAdmin(function () use ($course, $activeQuestion, $disabledQuestion) {
            $videoModule = VideoModule::create(['course_id' => $course->id, 'title' => 'Test-Modul B12', 'sort_order' => 1]);
            $lesson = VideoLesson::create(['video_module_id' => $videoModule->id, 'title' => 'Test-Lektion B12', 'video_url' => 'https://example.test/video.mp4', 'sort_order' => 1]);
            $lesson->questions()->attach([$disabledQuestion->id => ['sort_order' => 1], $activeQuestion->id => ['sort_order' => 2]]);

            return $lesson;
        });

        // Die deaktivierte Frage steht an Position 1 -- ohne den
        // active-Filter wäre sie die einzige Frage an dieser Position.
        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$lesson->id}/questions/1");

        $response->assertOk();
        $response->assertSee('Aktive Testfrage B12');
        $response->assertDontSee('Deaktivierte Testfrage B12');
    }

    /** @return array{0: CourseDefinition, 1: Module, 2: ContentQuestion, 3: ContentQuestion} */
    private function makeIsolatedCourseWithQuestions(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-B12-'.uniqid(),
                'name' => 'Test Kurs B12',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create(['code' => 'TEST-B12-MOD-'.uniqid(), 'name' => 'Test Modul B12', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id);

            $activeQuestion = $this->makeQuestion('Aktive Testfrage B12', true);
            $disabledQuestion = $this->makeQuestion('Deaktivierte Testfrage B12', false);
            $module->questions()->attach([$activeQuestion->id => ['required' => true], $disabledQuestion->id => ['required' => true]]);

            return [$course, $module, $activeQuestion, $disabledQuestion];
        });
    }

    private function makeQuestion(string $text, bool $active): ContentQuestion
    {
        $question = ContentQuestion::create([
            'content_id' => 'TEST-B12-Q-'.uniqid(),
            'language' => 'de-DE',
            'active' => $active,
        ]);
        $this->createdQuestionIds[] = $question->id;

        $revision = ContentQuestionRevision::create([
            'question_id' => $question->id,
            'revision_no' => 1,
            'question_type' => 'single_choice',
            'question_text' => $text,
            'editorial_status' => 'published',
            'rights_status' => 'licensed',
        ]);

        ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
        ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);

        return $question;
    }
}
