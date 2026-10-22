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
}
