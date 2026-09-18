<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Erklärendes Richtig-/Falsch-Feedback pro Frage (siehe
 * database/data/sbf_see_binnen_fragen_feedback.csv, importiert über
 * ImportContentFeedback): wird nach dem Beantworten unter der
 * bestehenden "Richtig!"/"Leider falsch."-Meldung angezeigt.
 */
class QuestionFeedbackTest extends TestCase
{
    use InteractsWithTenants;

    /** @var array<string, array{feedback_correct: ?string, feedback_incorrect: ?string}> Ursprungswerte geteilter, global genutzter Revisionen, die ein Test verändert hat. */
    private array $originalFeedback = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->originalFeedback as $revisionId => $original) {
                ContentQuestionRevision::where('id', $revisionId)->update($original);
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    /** Merkt sich den Originalzustand einer geteilten Revision, bevor der Test ihr Feedback überschreibt. */
    private function setFeedback(ContentQuestionRevision $revision, ?string $correct, ?string $incorrect): void
    {
        $this->originalFeedback[$revision->id] ??= [
            'feedback_correct' => $revision->feedback_correct,
            'feedback_incorrect' => $revision->feedback_incorrect,
        ];

        $this->onAdmin(fn () => $revision->update(['feedback_correct' => $correct, 'feedback_incorrect' => $incorrect]));
    }

    public function test_correct_feedback_is_shown_after_a_correct_answer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $this->setFeedback($revision, 'E2E: Das ist die richtige Erklärung.', 'E2E: Das ist die falsche Erklärung.');
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
            'response_time_ms' => 1000,
        ]);

        $response->assertOk();
        $response->assertSee('Richtig!');
        $response->assertSee('E2E: Das ist die richtige Erklärung.');
        $response->assertDontSee('E2E: Das ist die falsche Erklärung.');
    }

    public function test_incorrect_feedback_is_shown_after_a_wrong_answer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $this->setFeedback($revision, 'E2E: Das ist die richtige Erklärung.', 'E2E: Das ist die falsche Erklärung.');
        $wrongAnswer = $revision->answers->firstWhere('is_correct', false);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $wrongAnswer->id,
            'mode' => 'smarttrainer',
            'response_time_ms' => 1000,
        ]);

        $response->assertOk();
        $response->assertSee('Leider falsch.');
        $response->assertSee('E2E: Das ist die falsche Erklärung.');
        $response->assertDontSee('E2E: Das ist die richtige Erklärung.');
    }

    public function test_no_feedback_block_is_rendered_when_none_is_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [, $revision] = $this->anyPublishedQuestionIn($module);
        $this->setFeedback($revision, null, null);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
            'response_time_ms' => 1000,
        ]);

        $response->assertOk();
        $response->assertSee('Richtig!');
    }

    public function test_superadmin_can_set_feedback_when_editing_a_question(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->existingModule('SRC');

        $create = $this->actingAs($superadmin)->post("/superadmin/modules/{$module->id}/questions", [
            'question_text' => 'E2E: Feedback-Testfrage',
            'answers' => ['A' => 'Antwort A', 'B' => 'Antwort B', 'C' => 'Antwort C', 'D' => 'Antwort D'],
            'correct' => 'A',
            'feedback_correct' => 'E2E: Korrekt, weil...',
            'feedback_incorrect' => 'E2E: Falsch, weil...',
        ]);
        $create->assertRedirect();

        $this->assertDatabaseHas('content_question_revision', [
            'question_text' => 'E2E: Feedback-Testfrage',
            'feedback_correct' => 'E2E: Korrekt, weil...',
            'feedback_incorrect' => 'E2E: Falsch, weil...',
        ]);

        // Aufräumen: global angelegte Testfrage wieder entfernen (kein
        // originalFeedback-Restore nötig, die Frage existierte vorher nicht).
        $this->onAdmin(function () {
            $revision = ContentQuestionRevision::where('question_text', 'E2E: Feedback-Testfrage')->first();
            if ($revision) {
                ContentAnswer::where('revision_id', $revision->id)->delete();
                $questionId = $revision->question_id;
                ContentQuestionRevision::where('question_id', $questionId)->delete();
                DB::table('module_content')->where('question_id', $questionId)->delete();
                ContentQuestion::where('id', $questionId)->delete();
            }
        });
    }

    public function test_import_command_backfilled_feedback_and_stripped_the_redundant_verdict_prefix(): void
    {
        $question = ContentQuestion::where('content_id', 'SBF-BASIS-0001')->first();

        if (! $question) {
            $this->markTestSkipped('Zentrale Fragenbasis (SBF-BASIS-0001) ist in dieser Umgebung nicht importiert.');
        }

        $revision = $question->publishedRevision();
        $this->assertNotNull($revision->feedback_correct);
        $this->assertNotNull($revision->feedback_incorrect);
        $this->assertStringStartsNotWith('Richtig.', $revision->feedback_correct);
        $this->assertStringStartsNotWith('Falsch.', $revision->feedback_incorrect);
    }
}
