<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\Progress;
use PDO;
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
        // Bug-Review (Lernfortschritt bei ersetzten Fragen): muss vor dem
        // Löschen der Frage selbst laufen -- ein per Test angelegter
        // Attempt verweist per Fremdschlüssel auf content_question und
        // blockiert dessen Löschung sonst (siehe Testfall "...resets
        // learner progress but keeps attempts").
        $this->cleanUpCreatedTenantsAndUsers();

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

    /**
     * Bug-Review (Lernfortschritt bei ersetzten Fragen): die neue Fassung
     * oben hat einen anderen Fragetext -- bisheriger Mastery-Fortschritt
     * eines Lerners für die ALTE Fassung muss deshalb auf "neu"
     * zurückgesetzt werden. Ein bereits dokumentierter Attempt bleibt dabei
     * als historischer Beleg unangetastet.
     */
    public function test_publishing_a_revision_with_changed_content_resets_learner_progress_but_keeps_attempts(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, $published, $review] = $this->makeQuestionWithPendingReview();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $answer = $this->onAdmin(fn () => ContentAnswer::create([
            'revision_id' => $published->id, 'answer_key' => 'A', 'answer_text' => 'Richtig', 'is_correct' => true, 'sort_order' => 1,
        ]));

        $this->onAdmin(fn () => Progress::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $question->id,
            'learning_state' => 'gefestigt',
            'attempt_count' => 3,
            'correct_count' => 3,
            'current_streak' => 3,
        ]));

        $attempt = $this->onAdmin(fn () => Attempt::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $question->id,
            'revision_id' => $published->id,
            'selected_answer_id' => $answer->id,
            'correct' => true,
            'context' => 'smarttrainer',
        ]));

        $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $review))->assertRedirect();

        $this->onAdmin(function () use ($tenant, $learner, $question, $attempt) {
            $this->assertDatabaseMissing('progress', [
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
            ]);

            $this->assertTrue(Attempt::whereKey($attempt->id)->exists(), 'Ein historischer Attempt darf durch die Veröffentlichung nicht verschwinden.');
        });
    }

    /**
     * Bug-Review (Doppelklick auf "Veröffentlichen" verursacht Fehler 500):
     * zwei nahezu gleichzeitige Klicks bestehen beide die Prüfungen vor
     * der Transaktion (editorial_status war für beide noch "review"), und
     * laufen parallel in die Medien-Übernahme -- derselbe
     * (revision_id, media_asset_id, role)-Primärschlüssel wurde dadurch
     * zweimal eingefügt. Um das ohne künstlich "unecht" zu testen (ein
     * rein sequenzieller zweiter Request würde die bereits committete
     * erste Veröffentlichung einfach vorfinden und den Fehler NICHT
     * reproduzieren, siehe DoubleSubmitRaceTest), simuliert ein echter
     * Kindprozess (pcntl_fork) eine zweite, genau wie der echte Code
     * ablaufende Veröffentlichung, die die Frage-Zeile sperrt und absichtlich
     * kurz offen hält, bevor sie committet -- exakt das Zeitfenster, das
     * ein Doppelklick erzeugt.
     */
    public function test_double_clicking_publish_does_not_cause_http_500(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix-Erweiterung nicht verfügbar.');
        }

        $superadmin = $this->createSuperAdmin();
        [, $question, $published, $review] = $this->makeQuestionWithPendingReview();

        $media = $this->onAdmin(function () use ($published) {
            $media = MediaAsset::create([
                'asset_key' => 'test-w5-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => '/images/test-w5.png',
                'status' => 'published',
            ]);
            $this->createdMediaAssetIds[] = $media->id;
            $published->media()->attach($media->id, ['role' => 'question', 'sort_order' => 1]);

            return $media;
        });

        $adminConfig = config('database.connections.pgsql_admin');

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Kindprozess: fährt exakt denselben Ablauf wie
            // publishRevision() -- Frage-Zeile sperren, Medien übernehmen,
            // alte Fassung deprecaten, neue veröffentlichen -- hält die
            // Transaktion aber absichtlich offen, bevor sie committet.
            try {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $adminConfig['host'], $adminConfig['port'], $adminConfig['database']);
                $pdo = new PDO($dsn, $adminConfig['username'], $adminConfig['password']);
                $pdo->beginTransaction();
                $pdo->prepare('SELECT id FROM content_question WHERE id = :q FOR UPDATE')->execute(['q' => $question->id]);
                $pdo->prepare('INSERT INTO question_media (revision_id, media_asset_id, role, sort_order) VALUES (:r, :m, :role, 1)')
                    ->execute(['r' => $review->id, 'm' => $media->id, 'role' => 'question']);
                $pdo->prepare("UPDATE content_question_revision SET editorial_status = 'deprecated' WHERE id = :id")->execute(['id' => $published->id]);
                $pdo->query('SELECT pg_sleep(0.4)');
                $pdo->prepare("UPDATE content_question_revision SET editorial_status = 'published' WHERE id = :id")->execute(['id' => $review->id]);
                $pdo->commit();
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        // Dem Kindprozess Zeit geben, die Frage-Zeile zu sperren und die
        // Medien-Zuordnung (noch uncommittet) einzufügen, bevor der echte
        // Request genau in dieses Zeitfenster trifft.
        usleep(150_000);

        $response = $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.publish', $review));

        pcntl_waitpid($pid, $status);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($question, $review) {
            $this->assertSame('published', $review->fresh()->editorial_status);
            $this->assertSame($review->id, $question->publishedRevision()->id);
            $this->assertSame(1, \DB::table('question_media')->where('revision_id', $review->id)->count(), 'Die Medienzuordnung darf nicht doppelt eingefügt worden sein.');
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

    /**
     * Bug-Review (AA9: Wartende Import-Änderung verschwindet): die
     * Fragenliste bestimmte die wartende Review-Revision bisher ebenfalls
     * über "ist die insgesamt letzte Revision dieser Frage" -- speichert der
     * Superadmin zwischendurch über den Editor (siehe
     * QuestionEditorMediaFromPublishedRevisionTest), entsteht dadurch eine
     * neue, noch höher nummerierte veröffentlichte Revision, und das
     * "Review ausstehend"-Badge samt Veröffentlichen-Link verschwand dadurch
     * unbemerkt aus der Liste, obwohl die Review-Revision selbst unverändert
     * weiter auf Freigabe wartete.
     */
    public function test_question_list_still_shows_the_pending_review_badge_after_the_editor_saves_a_new_revision(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$module, $question, , $review] = $this->makeQuestionWithPendingReview();

        $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Alte Fassung (vom Editor überarbeitet)',
            'answers' => ['A' => 'Richtig', 'B' => 'Falsch', 'C' => 'Falsch', 'D' => 'Falsch'],
            'correct' => 'A',
        ])->assertRedirect(route('superadmin.questions.edit', $question));

        $response = $this->actingAs($superadmin)->get(route('superadmin.questions.index', $module));

        $response->assertOk();
        $response->assertSee('Review ausstehend');
        $response->assertSee(route('superadmin.questions.revisions.publish', $review));
    }

    public function test_edit_page_warns_when_the_pending_review_is_older_than_an_editor_change_and_it_can_be_discarded(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $question, , $review] = $this->makeQuestionWithPendingReview();

        $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => 'Alte Fassung (vom Editor überarbeitet)',
            'answers' => ['A' => 'Richtig', 'B' => 'Falsch', 'C' => 'Falsch', 'D' => 'Falsch'],
            'correct' => 'A',
        ])->assertRedirect(route('superadmin.questions.edit', $question));

        $this->actingAs($superadmin)->get(route('superadmin.questions.edit', $question))
            ->assertOk()
            ->assertSee('ist neuer und wurde im Editor geändert')
            ->assertSee(route('superadmin.questions.revisions.discard', $review));

        $this->actingAs($superadmin)->post(route('superadmin.questions.revisions.discard', $review))->assertRedirect();

        $this->onAdmin(function () use ($question, $review) {
            $this->assertSame('deprecated', $review->fresh()->editorial_status);
            $this->assertSame('Alte Fassung (vom Editor überarbeitet)', $question->fresh()->publishedRevision()->question_text);
            $this->assertNull($question->fresh()->pendingReviewRevision());
        });
    }
}
