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
 * Bug-Review T3: ImportContentQuestions (Bug-Review F8) kann neben der
 * veröffentlichten Revision eine neuere "review"-Revision mit höherer
 * revision_no anlegen, die auf die Freigabe durch publishRevision()
 * wartet. Superadmin\QuestionController::update() ermittelte die für die
 * Medien-Übernahme (Bug-Review B13) maßgebliche Vorgänger-Revision bisher
 * über $question->revisions()->orderByDesc('revision_no')->first() -- bei
 * einer wartenden Review-Revision ist das genau diese, nicht die
 * tatsächlich im Editor-Formular angezeigte und bearbeitete veröffentlichte
 * Fassung (siehe edit(): publishedRevision() ?? Fallback). Eine Bearbeitung
 * übernahm dadurch die Medien der (dem Superadmin unsichtbaren) Review-
 * Revision statt die der gerade bearbeiteten veröffentlichten Fassung.
 */
class QuestionEditorMediaFromPublishedRevisionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
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

    public function test_editing_a_question_with_a_pending_review_revision_keeps_the_published_images_not_the_reviews(): void
    {
        $superadmin = $this->createSuperAdmin();

        [$question, $publishedMedia, $reviewRevision] = $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-T3-MOD-'.uniqid(), 'name' => 'Test Modul T3', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-T3-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $published = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Welches Zeichen zeigt diese Tonne?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'C', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 3]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'D', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 4]);

            $publishedMedia = MediaAsset::create([
                'asset_key' => 'test-t3-published-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => '/images/test-t3-published.png',
                'status' => 'published',
            ]);
            $this->createdMediaAssetIds[] = $publishedMedia->id;
            $published->media()->attach($publishedMedia->id, ['role' => 'question', 'sort_order' => 1]);

            // Simuliert exakt das, was ImportContentQuestions bei einem
            // geänderten Reimport anlegt: eine neuere Revision im Status
            // "review", die auf publishRevision() wartet und ein ANDERES
            // Bild trägt als die aktuell veröffentlichte Fassung.
            $review = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 2,
                'question_type' => 'single_choice',
                'question_text' => 'Welches Zeichen zeigt diese Tonne? (Reimport-Entwurf)',
                'editorial_status' => 'review',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'C', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 3]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'D', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 4]);

            $reviewMedia = MediaAsset::create([
                'asset_key' => 'test-t3-review-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => '/images/test-t3-review.png',
                'status' => 'published',
            ]);
            $this->createdMediaAssetIds[] = $reviewMedia->id;
            $review->media()->attach($reviewMedia->id, ['role' => 'question', 'sort_order' => 1]);

            return [$question, $publishedMedia, $review];
        });

        // Der Superadmin sieht und bearbeitet im Formular (siehe edit())
        // weiterhin die veröffentlichte Fassung -- die Review-Revision wird
        // dort nur separat als Vorschau angezeigt.
        $response = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Welches Zeichen zeigt diese Tonne? (überarbeitet)',
            'answers' => ['A' => 'Richtig', 'B' => 'Falsch', 'C' => 'Falsch', 'D' => 'Falsch'],
            'correct' => 'A',
        ]);

        $response->assertRedirect(route('superadmin.questions.edit', $question));

        $this->onAdmin(function () use ($question, $publishedMedia, $reviewRevision) {
            $newRevision = $question->revisions()->where('editorial_status', 'published')->orderByDesc('revision_no')->first();

            $this->assertSame(3, $newRevision->revision_no);
            $this->assertSame(
                [$publishedMedia->id],
                $newRevision->media()->pluck('media_asset.id')->all(),
                'Die neue Revision muss das Bild der bisher veröffentlichten Fassung übernehmen, nicht das der wartenden Review-Revision.'
            );

            // Die Review-Revision bleibt unverändert als Status "review"
            // bestehen -- update() darf sie weder veröffentlichen noch
            // sonst anfassen.
            $reviewRevision->refresh();
            $this->assertSame('review', $reviewRevision->editorial_status);

            // Bug-Review (AA9: Wartende Import-Änderung verschwindet): die
            // Review-Revision hat jetzt eine KLEINERE revision_no als die
            // soeben vom Editor angelegte veröffentlichte Revision (2 < 3)
            // -- pendingReviewRevision() darf sie deshalb nicht für
            // "veraltet" halten und muss sie weiterhin liefern, statt sie
            // für immer unsichtbar werden zu lassen.
            $this->assertNotNull(
                $question->pendingReviewRevision(),
                'Die wartende Review-Revision darf nach einem Editor-Speichern nicht unsichtbar werden.'
            );
            $this->assertSame($reviewRevision->id, $question->pendingReviewRevision()->id);
        });
    }

    /**
     * Bug-Review (AA9: Wartende Import-Änderung verschwindet): auch
     * publishRevision() verglich die Review-Revision bisher gegen die
     * insgesamt höchste revision_no der Frage -- nach einem Editor-
     * Speichern (siehe Testfall oben) war das die neue veröffentlichte
     * Fassung, nicht mehr die Review-Revision selbst, und die Veröffent-
     * lichung schlug deshalb dauerhaft mit 409 fehl, auch nach einem erneuten
     * Laden der Seite (der Hinweistext half also nicht).
     */
    public function test_a_pending_review_revision_can_still_be_published_after_the_editor_saves_a_new_revision(): void
    {
        $superadmin = $this->createSuperAdmin();

        [$question, , $reviewRevision] = $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-AA9-MOD-'.uniqid(), 'name' => 'Test Modul AA9', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-AA9-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $published = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Alte Fassung',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'C', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 3]);
            ContentAnswer::create(['revision_id' => $published->id, 'answer_key' => 'D', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 4]);

            $review = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 2,
                'question_type' => 'single_choice',
                'question_text' => 'Neue Fassung aus Reimport',
                'editorial_status' => 'review',
                'rights_status' => 'review',
            ]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'B', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 2]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'C', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 3]);
            ContentAnswer::create(['revision_id' => $review->id, 'answer_key' => 'D', 'answer_text' => 'Falsch', 'is_correct' => false, 'sort_order' => 4]);

            return [$question, $published, $review];
        });

        // Der Superadmin bearbeitet die veröffentlichte Fassung über den
        // Editor, ohne die wartende Review-Revision zu beachten -- das legt
        // eine weitere, noch höher nummerierte veröffentlichte Revision an.
        $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Alte Fassung (vom Editor überarbeitet)',
            'answers' => ['A' => 'Richtig', 'B' => 'Falsch', 'C' => 'Falsch', 'D' => 'Falsch'],
            'correct' => 'A',
        ])->assertRedirect(route('superadmin.questions.edit', $question));

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $reviewRevision));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($question, $reviewRevision) {
            $reviewRevision->refresh();
            $this->assertSame('published', $reviewRevision->editorial_status);
            $this->assertSame($reviewRevision->id, $question->publishedRevision()->id);
        });
    }
}
