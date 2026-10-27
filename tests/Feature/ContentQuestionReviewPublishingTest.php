<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
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
