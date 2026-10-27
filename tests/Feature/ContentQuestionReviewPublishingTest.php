<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review F8: ImportContentQuestions legt bei einem geänderten
 * Reimport eine neue Revision im Status "review" an -- es gab bisher
 * keinen Weg, sie zu veröffentlichen, sodass geänderte Inhalte Lerner nie
 * erreichten (publishedRevision() lieferte dauerhaft die alte Fassung).
 */
class ContentQuestionReviewPublishingTest extends TestCase
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

    /** @return array{0: Module, 1: ContentQuestion, 2: ContentQuestionRevision, 3: ContentQuestionRevision} */
    private function makeQuestionWithPendingReview(): array
    {
        return $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-F8-MOD-'.uniqid(), 'name' => 'Test Modul F8', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-F8-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
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

            // Simuliert einen erneuten Content-Import, der die Frage
            // inhaltlich geändert hat (siehe ImportContentQuestions).
            $review = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 2,
                'question_type' => 'single_choice',
                'question_text' => 'Neue Fassung aus Reimport',
                'editorial_status' => 'review',
                'rights_status' => 'review',
            ]);

            return [$module, $question, $published, $review];
        });
    }

    public function test_publishing_a_review_revision_makes_it_the_current_published_one(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, $published, $review] = $this->makeQuestionWithPendingReview();

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $review));

        $response->assertRedirect();
        $this->onAdmin(function () use ($question, $published, $review) {
            $this->assertSame('deprecated', $published->fresh()->editorial_status);
            $this->assertSame('published', $review->fresh()->editorial_status);
            $this->assertSame($review->id, $question->publishedRevision()->id);
            $this->assertSame('Neue Fassung aus Reimport', $question->publishedRevision()->question_text);
        });
    }

    public function test_publishing_a_revision_that_is_not_pending_review_is_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, , $published] = $this->makeQuestionWithPendingReview();

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $published));

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertSame('published', $published->fresh()->editorial_status));
    }

    /**
     * Bug-Review (Veröffentlichen einer Import-Revision verliert Feedback
     * und Bilder): ImportContentQuestions setzt auf einer neuen
     * "review"-Revision nie feedback_correct/feedback_incorrect und hängt
     * kein Bild an (beides reine Editor-Felder) -- ohne das von der bisher
     * veröffentlichten Fassung zu übernehmen, hätte das Veröffentlichen
     * einer solchen Revision beides stillschweigend gelöscht.
     */
    public function test_publishing_a_review_revision_carries_over_feedback_and_media_from_the_previously_published_revision(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, $published, $review] = $this->makeQuestionWithPendingReview();

        $media = $this->onAdmin(function () use ($published) {
            $media = MediaAsset::create([
                'asset_key' => 'test-o5-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => '/images/test-o5.png',
                'status' => 'published',
            ]);
            $this->createdMediaAssetIds[] = $media->id;
            $published->media()->attach($media->id, ['role' => 'question', 'sort_order' => 1]);
            $published->update(['feedback_correct' => 'Genau richtig!', 'feedback_incorrect' => 'Leider nicht.']);

            return $media;
        });

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $review));

        $response->assertRedirect();

        $this->onAdmin(function () use ($question, $media) {
            $publishedNow = $question->publishedRevision();
            $this->assertSame('Genau richtig!', $publishedNow->feedback_correct);
            $this->assertSame('Leider nicht.', $publishedNow->feedback_incorrect);
            $this->assertSame([$media->id], $publishedNow->media()->pluck('media_asset.id')->all());
        });
    }

    /**
     * Bug-Review (Veröffentlichen einer Import-Revision verliert Feedback
     * und Bilder): ImportContentQuestions prüft beim Anlegen einer neuen
     * "review"-Revision nur die insgesamt letzte Revision, nicht gezielt
     * nach einer dort schon wartenden -- zwei Reimports in Folge können
     * deshalb zwei "review"-Revisionen stapeln. Ein noch offener Link auf
     * die ältere davon darf sie nicht mehr veröffentlichen dürfen, sobald
     * eine neuere existiert.
     */
    public function test_publishing_a_stale_review_revision_is_rejected_once_a_newer_one_exists(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, , $staleReview] = $this->makeQuestionWithPendingReview();

        $newerReview = $this->onAdmin(fn () => ContentQuestionRevision::create([
            'question_id' => $question->id,
            'revision_no' => 3,
            'question_type' => 'single_choice',
            'question_text' => 'Noch neuere Fassung aus einem zweiten Reimport',
            'editorial_status' => 'review',
            'rights_status' => 'review',
        ]));

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $staleReview));

        $response->assertStatus(409);
        $this->onAdmin(function () use ($staleReview, $newerReview) {
            $this->assertSame('review', $staleReview->fresh()->editorial_status);
            $this->assertSame('review', $newerReview->fresh()->editorial_status);
        });
    }

    public function test_question_list_shows_a_pending_review_badge_and_publish_action(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$module, , , $review] = $this->makeQuestionWithPendingReview();

        $response = $this->actingAs($superadmin)->get(route('superadmin.questions.index', $module));

        $response->assertOk();
        $response->assertSee('Review ausstehend');
        $response->assertSee(route('superadmin.questions.revisions.publish', $review));
    }

    public function test_question_edit_page_shows_the_pending_review_draft_with_a_publish_action(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, , $review] = $this->makeQuestionWithPendingReview();

        $response = $this->actingAs($superadmin)->get(route('superadmin.questions.edit', $question));

        $response->assertOk();
        $response->assertSee('Neue Fassung aus Reimport');
        $response->assertSee(route('superadmin.questions.revisions.publish', $review));
    }
}
