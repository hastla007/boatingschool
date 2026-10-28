<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B13: Superadmin\QuestionController::update() legt bei jeder
 * Bearbeitung eine neue, unveränderliche Revision an -- bisher ohne die
 * Medienzuordnungen (question_media) der Vorgänger-Revision zu übernehmen.
 * Ein Fragenbild verschwand dadurch beim nächsten Speichern, obwohl sich am
 * Bild selbst nichts geändert hatte.
 */
class QuestionMediaCarryoverTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            // Reihenfolge: zuerst das Modul (kaskadiert module_content weg),
            // erst danach die Frage selbst (sonst verhindert die
            // Fremdschlüsselreferenz aus module_content das Löschen).
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
            foreach ($this->createdMediaAssetIds as $id) {
                MediaAsset::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_editing_a_question_keeps_its_existing_image(): void
    {
        $superadmin = $this->createSuperAdmin();

        [$question, $revision, $media] = $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-B13-MOD-'.uniqid(), 'name' => 'Test Modul B13', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-B13-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Welches Zeichen zeigt diese Tonne?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'C', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 3]);
            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'D', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 4]);

            $media = MediaAsset::create([
                'asset_key' => 'test-b13-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => '/images/test-b13.png',
                'status' => 'published',
            ]);
            $this->createdMediaAssetIds[] = $media->id;
            $revision->media()->attach($media->id, ['role' => 'question', 'sort_order' => 1]);

            return [$question, $revision, $media];
        });

        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Welches Zeichen zeigt diese Tonne? (überarbeitet)',
            'answers' => ['A' => 'Richtig', 'B' => 'Falsch', 'C' => 'Falsch', 'D' => 'Falsch'],
            'correct' => 'A',
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $newRevision = $this->onAdmin(fn () => $question->revisions()->where('editorial_status', 'published')->orderByDesc('revision_no')->first());

        $this->assertNotSame($revision->id, $newRevision->id);
        $this->assertSame([$media->id], $this->onAdmin(fn () => $newRevision->media()->pluck('media_asset.id')->all()));
    }
}
