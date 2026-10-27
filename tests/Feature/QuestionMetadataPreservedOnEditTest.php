<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Fragen-Editor verliert Metadaten): image_required, Quelle
 * (source_catalog/source_version/source_page/source_question_id), Kompetenz
 * (competency) und Gültigkeit (valid_from/valid_to) kannte das Editor-
 * Formular bisher gar nicht -- jede Bearbeitung nullte diese Felder auf der
 * neuen Revision, obwohl der Import (ImportContentQuestions/
 * FknSknContentSeeder) sie bestückt. official_number (auf content_question,
 * nicht auf der Revision) wurde bei einer bestehenden Frage im Formular gar
 * nicht erst angezeigt und von update() nie geschrieben. Da competency/
 * image_required/source_question_id zusätzlich in
 * ImportContentQuestions::hashRevision() einfließen, erzeugte ein
 * nachfolgender Reimport dadurch lautlos eine neue Review-Revision.
 */
class QuestionMetadataPreservedOnEditTest extends TestCase
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
        $module = Module::create(['code' => 'TEST-O4-MOD-'.uniqid(), 'name' => 'Test Modul O4', 'version' => '1', 'status' => 'published']);
        $this->createdModuleIds[] = $module->id;

        return $module;
    }

    public function test_editing_a_question_preserves_import_metadata_instead_of_nulling_it(): void
    {
        $superadmin = $this->createSuperAdmin();

        $question = $this->onAdmin(function () {
            $module = $this->makeModule();
            $question = ContentQuestion::create([
                'content_id' => 'TEST-O4-Q-'.uniqid(),
                'official_number' => '12.34.56',
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Welche Farbe hat die Steuerbordseite-Laterne?',
                'competency' => 'Seezeichen',
                'image_required' => true,
                'source_catalog' => 'Zentrale Fragenbasis SBF/SRC/UBI',
                'source_version' => '2026-09',
                'source_page' => '42',
                'source_question_id' => 'SBF-12.34.56',
                'valid_from' => '2024-01-01',
                'valid_to' => '2029-12-31',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            foreach (['A', 'B', 'C', 'D'] as $i => $key) {
                ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => $key, 'answer_text' => $key, 'is_correct' => $key === 'A', 'sort_order' => $i + 1]);
            }

            return $question;
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Welche Farbe hat die Steuerbordseite-Laterne? (überarbeitet)',
            'official_number' => '12.34.56',
            'competency' => 'Seezeichen',
            'image_required' => '1',
            'source_catalog' => 'Zentrale Fragenbasis SBF/SRC/UBI',
            'source_version' => '2026-09',
            'source_page' => '42',
            'source_question_id' => 'SBF-12.34.56',
            'valid_from' => '2024-01-01',
            'valid_to' => '2029-12-31',
            'answers' => ['A' => 'Grün', 'B' => 'Rot', 'C' => 'Weiß', 'D' => 'Gelb'],
            'correct' => 'A',
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $fresh = $this->onAdmin(fn () => $question->fresh());
        $latest = $this->onAdmin(fn () => $fresh->publishedRevision());

        $this->assertSame('12.34.56', $fresh->official_number);
        $this->assertSame('Seezeichen', $latest->competency);
        $this->assertTrue((bool) $latest->image_required);
        $this->assertSame('Zentrale Fragenbasis SBF/SRC/UBI', $latest->source_catalog);
        $this->assertSame('2026-09', $latest->source_version);
        $this->assertSame('42', $latest->source_page);
        $this->assertSame('SBF-12.34.56', $latest->source_question_id);
        $this->assertSame('2024-01-01', $latest->valid_from->format('Y-m-d'));
        $this->assertSame('2029-12-31', $latest->valid_to->format('Y-m-d'));
    }

    public function test_unchecking_image_required_on_edit_actually_clears_it(): void
    {
        $superadmin = $this->createSuperAdmin();

        $question = $this->onAdmin(function () {
            $module = $this->makeModule();
            $question = ContentQuestion::create(['content_id' => 'TEST-O4-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Testfrage',
                'image_required' => true,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            foreach (['A', 'B', 'C', 'D'] as $i => $key) {
                ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => $key, 'answer_text' => $key, 'is_correct' => $key === 'A', 'sort_order' => $i + 1]);
            }

            return $question;
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Testfrage',
            'answers' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'],
            'correct' => 'A',
            // image_required bewusst nicht mitgeschickt -- genau das macht
            // ein Browser beim Abhaken einer Checkbox.
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $latest = $this->onAdmin(fn () => $question->fresh()->publishedRevision());
        $this->assertFalse((bool) $latest->image_required);
    }
}
