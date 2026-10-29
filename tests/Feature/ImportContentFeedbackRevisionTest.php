<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B27: ImportContentFeedback schrieb die Feedback-Texte bisher
 * direkt auf die veröffentlichte Revision statt -- konsistent mit
 * Superadmin\QuestionController::update() -- eine neue Revision anzulegen.
 * Das verletzte die Unveränderlichkeit veröffentlichter Revisionen, auf die
 * sich historische exam_session_question/attempt-Zeilen per revision_id
 * verlassen.
 */
class ImportContentFeedbackRevisionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    private ?string $csvPath = null;

    protected function tearDown(): void
    {
        if ($this->csvPath && file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }

        $this->onAdmin(function () {
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_backfilling_feedback_creates_a_new_revision_instead_of_mutating_the_published_one(): void
    {
        $contentId = 'TEST-B27-'.uniqid();

        [$question, $oldRevision, $answer] = $this->onAdmin(function () use ($contentId) {
            $module = Module::create(['code' => 'TEST-B27-MOD-'.uniqid(), 'name' => 'Test Modul B27', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => $contentId, 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'B27 Testfrage',
                'feedback_correct' => null,
                'feedback_incorrect' => null,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            $answer = ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);

            return [$question, $revision, $answer];
        });

        $this->csvPath = storage_path('app/test-b27-feedback-'.uniqid().'.csv');
        file_put_contents($this->csvPath, implode(';', ['content_id', 'feedback_correct', 'feedback_incorrect'])."\n"
            .implode(';', [$contentId, 'Richtig. Neuer Erklärtext.', 'Falsch. Neuer Falsch-Text.'])."\n");

        Artisan::call('content:import-feedback', ['path' => 'storage/app/'.basename($this->csvPath)]);

        $this->onAdmin(function () use ($question, $oldRevision, $answer) {
            $oldRevision->refresh();
            $this->assertSame('deprecated', $oldRevision->editorial_status);
            // Die alte Revision selbst bleibt unangetastet (kein Feedback nachträglich hineingeschrieben).
            $this->assertNull($oldRevision->feedback_correct);
            $this->assertNull($oldRevision->feedback_incorrect);

            $newRevision = $question->publishedRevision();
            $this->assertNotSame($oldRevision->id, $newRevision->id);
            $this->assertSame(2, $newRevision->revision_no);
            $this->assertSame('Neuer Erklärtext.', $newRevision->feedback_correct);
            $this->assertSame('Neuer Falsch-Text.', $newRevision->feedback_incorrect);
            $this->assertSame('B27 Testfrage', $newRevision->question_text);

            $newAnswers = $newRevision->answers()->orderBy('sort_order')->get();
            $this->assertCount(2, $newAnswers);
            $this->assertSame('Richtig', $newAnswers->firstWhere('is_correct', true)->answer_text);
            $this->assertNotSame($answer->id, $newAnswers->first()->id);
        });
    }

    /**
     * Bug-Review F8: lag bereits eine neuere, noch unveröffentlichte
     * Revision im Status "review" vor (z. B. aus einem erneuten
     * Content-Import, siehe ImportContentQuestions), berechnete sich die
     * neue revision_no hier bisher aus der veröffentlichten Revision + 1
     * -- das kollidierte mit dem Unique-Index (question_id, revision_no)
     * und brach den gesamten Feedback-Import mit einer unabgefangenen
     * QueryException ab, statt nur diese eine Zeile zu überspringen.
     */
    public function test_feedback_import_does_not_abort_when_a_pending_review_revision_already_exists(): void
    {
        $contentId = 'TEST-F8-'.uniqid();

        [$question, $publishedRevision, $reviewRevision] = $this->onAdmin(function () use ($contentId) {
            $module = Module::create(['code' => 'TEST-F8-MOD-'.uniqid(), 'name' => 'Test Modul F8', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => $contentId, 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $publishedRevision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'F8 Testfrage',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $publishedRevision->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $publishedRevision->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);

            // Simuliert einen erneuten Content-Import, der die Frage
            // inhaltlich geändert hat -- die neue Fassung wartet im Status
            // "review" auf Veröffentlichung (siehe ImportContentQuestions).
            $reviewRevision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 2,
                'question_type' => 'single_choice',
                'question_text' => 'F8 Testfrage (überarbeitet)',
                'editorial_status' => 'review',
                'rights_status' => 'review',
            ]);

            return [$question, $publishedRevision, $reviewRevision];
        });

        $this->csvPath = storage_path('app/test-f8-feedback-'.uniqid().'.csv');
        file_put_contents($this->csvPath, implode(';', ['content_id', 'feedback_correct', 'feedback_incorrect'])."\n"
            .implode(';', [$contentId, 'Richtig. Erklärtext.', 'Falsch. Falsch-Text.'])."\n");

        $exitCode = Artisan::call('content:import-feedback', ['path' => 'storage/app/'.basename($this->csvPath)]);

        $this->assertSame(0, $exitCode);

        $this->onAdmin(function () use ($question, $publishedRevision, $reviewRevision) {
            $publishedRevision->refresh();
            $this->assertSame('deprecated', $publishedRevision->editorial_status);

            // Die Review-Revision bleibt von der Feedback-Ergänzung unberührt.
            $reviewRevision->refresh();
            $this->assertSame('review', $reviewRevision->editorial_status);

            $newRevision = $question->publishedRevision();
            $this->assertNotNull($newRevision);
            $this->assertSame(3, $newRevision->revision_no);
            $this->assertSame('Erklärtext.', $newRevision->feedback_correct);
            $this->assertSame('Falsch-Text.', $newRevision->feedback_incorrect);
        });
    }

    /**
     * Bug-Review (Seed überschreibt Feedback-Text aus dem Editor): die
     * veröffentlichte Revision kann vom Fragen-Editor stammen und dort
     * bereits ein eigenes Feedback tragen -- ein Reimport der CSV durfte
     * dieses nicht verwerfen. Ein noch LEERES Feld wird dagegen weiterhin
     * aus der CSV befüllt.
     */
    public function test_reimporting_feedback_does_not_overwrite_feedback_already_entered_by_a_superadmin(): void
    {
        $contentId = 'TEST-W2-'.uniqid();

        [$question, $editorRevision] = $this->onAdmin(function () use ($contentId) {
            $module = Module::create(['code' => 'TEST-W2-MOD-'.uniqid(), 'name' => 'Test Modul W2', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => $contentId, 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $editorRevision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'W2 Testfrage',
                'feedback_correct' => 'Vom Superadmin korrigierter Erklärtext.',
                'feedback_incorrect' => null,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
                'edited_by_superadmin' => true,
            ]);
            ContentAnswer::create(['revision_id' => $editorRevision->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $editorRevision->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);

            return [$question, $editorRevision];
        });

        $this->csvPath = storage_path('app/test-w2-feedback-'.uniqid().'.csv');
        file_put_contents($this->csvPath, implode(';', ['content_id', 'feedback_correct', 'feedback_incorrect'])."\n"
            .implode(';', [$contentId, 'Richtig. Veralteter CSV-Text, der nicht übernommen werden darf.', 'Falsch. Neuer Falsch-Text aus der CSV.'])."\n");

        Artisan::call('content:import-feedback', ['path' => 'storage/app/'.basename($this->csvPath)]);

        $this->onAdmin(function () use ($question, $editorRevision) {
            $editorRevision->refresh();
            $this->assertSame('deprecated', $editorRevision->editorial_status);

            $newRevision = $question->publishedRevision();
            $this->assertNotSame($editorRevision->id, $newRevision->id);
            $this->assertSame(
                'Vom Superadmin korrigierter Erklärtext.',
                $newRevision->feedback_correct,
                'Ein vom Editor bereits gesetztes Feedback darf ein CSV-Reimport nicht überschreiben.'
            );
            $this->assertSame(
                'Neuer Falsch-Text aus der CSV.',
                $newRevision->feedback_incorrect,
                'Ein noch leeres Feedback-Feld soll weiterhin aus der CSV befüllt werden.'
            );
        });
    }
}
