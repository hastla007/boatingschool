<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): Superadmin\QuestionController::store()/update() setzten
 * question_type bisher immer hart auf 'single_choice' und die Validierung
 * verlangte zwingend genau die Antworten A-D plus ein "correct" --
 * unabhängig vom tatsächlichen Typ der bearbeiteten Frage. Jede Bearbeitung
 * einer multi_choice- oder open_text-Frage (siehe FknSknContentSeeder, 151
 * FKN/SKN-Fragen) erzeugte dadurch eine neue Revision, die deren echte
 * Antwortstruktur durch genau 4 Single-Choice-Antworten ersetzte.
 */
class QuestionTypePreservedOnEditTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
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

    private function makeModule(): Module
    {
        $module = Module::create(['code' => 'TEST-F4-MOD-'.uniqid(), 'name' => 'Test Modul F4', 'version' => '1', 'status' => 'published']);
        $this->createdModuleIds[] = $module->id;

        return $module;
    }

    public function test_editing_a_multi_choice_question_keeps_it_multi_choice_with_all_its_answers(): void
    {
        $superadmin = $this->createSuperAdmin();

        [$question, $revision] = $this->onAdmin(function () {
            $module = $this->makeModule();
            $question = ContentQuestion::create(['content_id' => 'TEST-F4-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'multi_choice',
                'question_text' => 'Was darf zur pyrotechnischen Notsignalgebung verwendet werden?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Signalpistole', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'PTB-Signalwaffen', 'is_correct' => true, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'C', 'answer_text' => 'Feuerwerkskörper', 'is_correct' => false, 'sort_order' => 3]);

            return [$question, $revision];
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'multi_choice',
            'question_text' => 'Was darf zur pyrotechnischen Notsignalgebung verwendet werden? (überarbeitet)',
            'multi_answers' => [
                ['text' => 'Signalpistole'],
                ['text' => 'PTB-Signalwaffen'],
                ['text' => 'Feuerwerkskörper'],
            ],
            'multi_correct' => ['0', '1'],
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $latest = $this->onAdmin(fn () => $question->fresh()->publishedRevision());

        $this->assertSame('multi_choice', $latest->question_type, 'Der Fragetyp darf durch eine Bearbeitung nicht lautlos auf single_choice wechseln.');
        $answers = $this->onAdmin(fn () => $latest->answers()->orderBy('sort_order')->get());
        $this->assertCount(3, $answers, 'Alle drei Antwortoptionen müssen erhalten bleiben, nicht nur vier erfundene A-D-Optionen.');
        $this->assertSame(2, $answers->where('is_correct', true)->count(), 'Multi-Choice erlaubt mehrere richtige Antworten gleichzeitig.');
    }

    public function test_editing_an_open_text_question_keeps_its_model_answer_instead_of_four_single_choice_options(): void
    {
        $superadmin = $this->createSuperAdmin();

        [$question, $revision] = $this->onAdmin(function () {
            $module = $this->makeModule();
            $question = ContentQuestion::create(['content_id' => 'TEST-F4-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'open_text',
                'question_text' => 'Wann dürfen pyrotechnische Notsignale verwendet werden?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => 'MODEL',
                'answer_text' => 'Nur im Notfall.',
                'is_correct' => true,
                'sort_order' => 1,
            ]);

            return [$question, $revision];
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'open_text',
            'question_text' => 'Wann dürfen pyrotechnische Notsignale verwendet werden? (überarbeitet)',
            'model_answer' => 'Nur im Notfall, d. h. wenn Gefahr für Leib und Leben besteht.',
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $latest = $this->onAdmin(fn () => $question->fresh()->publishedRevision());

        $this->assertSame('open_text', $latest->question_type, 'Der Fragetyp darf durch eine Bearbeitung nicht lautlos auf single_choice wechseln.');
        $answers = $this->onAdmin(fn () => $latest->answers()->get());
        $this->assertCount(1, $answers, 'Eine offene Frage hat genau eine Musterantwort, keine vier erfundenen A-D-Optionen.');
        $this->assertSame('Nur im Notfall, d. h. wenn Gefahr für Leib und Leben besteht.', $answers->first()->answer_text);
    }

    public function test_editing_a_question_with_a_different_question_type_than_the_existing_one_is_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();

        $question = $this->onAdmin(function () {
            $module = $this->makeModule();
            $question = ContentQuestion::create(['content_id' => 'TEST-F4-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'multi_choice',
                'question_text' => 'Testfrage',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'X', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Y', 'is_correct' => false, 'sort_order' => 2]);

            return $question;
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Testfrage',
            'answers' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'],
            'correct' => 'A',
        ]);

        $response->assertSessionHasErrors('question_type');
        $this->assertSame(1, $this->onAdmin(fn () => $question->fresh()->revisions()->count()), 'Ein abgelehnter Typwechsel darf keine neue Revision anlegen.');
    }

    public function test_creating_a_multi_choice_question_requires_at_least_one_correct_answer(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->onAdmin(fn () => $this->makeModule());

        $response = $this->actingAs($superadmin)->post("/superadmin/modules/{$module->id}/questions", [
            'question_type' => 'multi_choice',
            'question_text' => 'Neue Multi-Choice-Frage',
            'multi_answers' => [
                ['text' => 'Option 1'],
                ['text' => 'Option 2'],
            ],
            'multi_correct' => [],
        ]);

        $response->assertSessionHasErrors('multi_correct');
    }

    public function test_creating_a_multi_choice_question_with_valid_data_stores_all_answers(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->onAdmin(fn () => $this->makeModule());

        $response = $this->actingAs($superadmin)->post("/superadmin/modules/{$module->id}/questions", [
            'question_type' => 'multi_choice',
            'question_text' => 'Neue Multi-Choice-Frage',
            'multi_answers' => [
                ['text' => 'Option 1'],
                ['text' => 'Option 2'],
                ['text' => ''],
            ],
            'multi_correct' => ['0'],
        ]);

        $response->assertRedirect();

        $revision = ContentQuestionRevision::where('question_text', 'Neue Multi-Choice-Frage')->firstOrFail();
        $this->createdQuestionIds[] = $revision->question_id;

        $this->assertSame('multi_choice', $revision->question_type);
        $answers = $revision->answers()->orderBy('sort_order')->get();
        $this->assertCount(2, $answers, 'Die leere dritte Zeile darf keine Antwort erzeugen.');
        $this->assertTrue($answers->firstWhere('answer_key', 'A')->is_correct);
        $this->assertFalse($answers->firstWhere('answer_key', 'B')->is_correct);
    }
}
