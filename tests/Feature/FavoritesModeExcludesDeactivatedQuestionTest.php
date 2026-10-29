<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Deaktivierte Favoriten führen zu Endlosschleife):
 * LearningController::show() wählte im Favoriten-Modus bisher eine
 * zufällige favorisierte Fragen-ID, ohne zu prüfen, ob die Frage
 * inzwischen deaktiviert wurde (content_question.active = false) oder
 * ihre einzige Revision (noch) nicht veröffentlicht ist. War das der
 * einzige verbliebene Favorit, endete jeder Aufruf dieser Seite im
 * abort_if() weiter unten (keine veröffentlichte Fassung) -- ohne je einen
 * funktionierenden Favoriten oder die "keine Favoriten"-Ansicht zu
 * erreichen. Siehe SmarttrainerService::nextQuestion() (Bug-Review B12)
 * für denselben active-Filter in den übrigen Lernmodi.
 */
class FavoritesModeExcludesDeactivatedQuestionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
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

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_favorites_mode_skips_a_disabled_favorite_and_serves_another_one(): void
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

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=favorites");

        $response->assertOk();
        $response->assertViewHas('revision', fn ($revision) => $revision->question_id === $activeQuestion->id);
    }

    /**
     * War der deaktivierte Favorit der EINZIGE Favorit, endete dieser
     * Aufruf bisher immer im abort_if() (404) -- ohne Fix also eine
     * endlose, nie auflösbare Fehlerschleife statt der "keine
     * Favoriten"-Ansicht.
     */
    public function test_favorites_mode_shows_the_empty_state_when_the_only_favorite_is_disabled(): void
    {
        [$course, , , $disabledQuestion] = $this->makeIsolatedCourseWithQuestions();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => Favorite::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $disabledQuestion->id,
            'context' => Favorite::CONTEXT_SMART_LEARNING,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=favorites");

        $response->assertOk();
        $response->assertViewIs('learning.empty');
    }

    /** @return array{0: CourseDefinition, 1: Module, 2: ContentQuestion, 3: ContentQuestion} */
    private function makeIsolatedCourseWithQuestions(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-W8-'.uniqid(),
                'name' => 'Test Kurs W8',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create(['code' => 'TEST-W8-MOD-'.uniqid(), 'name' => 'Test Modul W8', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id);

            $activeQuestion = $this->makeQuestion('Aktive Testfrage W8', true);
            $disabledQuestion = $this->makeQuestion('Deaktivierte Testfrage W8', false);
            $module->questions()->attach([$activeQuestion->id => ['required' => true], $disabledQuestion->id => ['required' => true]]);

            return [$course, $module, $activeQuestion, $disabledQuestion];
        });
    }

    private function makeQuestion(string $text, bool $active): ContentQuestion
    {
        $question = ContentQuestion::create([
            'content_id' => 'TEST-W8-Q-'.uniqid(),
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
