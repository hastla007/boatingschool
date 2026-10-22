<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
use App\Models\Progress;
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
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        $sessionQuestionIds = $session->questions()->pluck('question_id')->all();
        $this->assertSame([$activeQuestion->id], $sessionQuestionIds);
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
