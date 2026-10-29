<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\ExamBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Progress;
use Database\Seeders\CatalogUpdateSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * UBI-Gesamtfragenkatalog Stand Oktober 2026 (ABVT Koblenz), die 12
 * Übungsfragebögen derselben Ausgabe und die amtliche Bestehensgrenze
 * 17 von 22 Fragen in 60 Minuten.
 */
class UbiCatalog2026Test extends TestCase
{
    use InteractsWithTenants;

    private ?string $fakeQuestionId = null;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        if ($this->fakeQuestionId) {
            $this->onAdmin(function () {
                Progress::where('question_id', $this->fakeQuestionId)->delete();
                $revisionIds = ContentQuestionRevision::where('question_id', $this->fakeQuestionId)->pluck('id');
                ContentAnswer::whereIn('revision_id', $revisionIds)->delete();
                ContentQuestionRevision::whereIn('id', $revisionIds)->delete();
                ContentQuestion::where('id', $this->fakeQuestionId)->delete();
            });
        }

        parent::tearDown();
    }

    public function test_all_130_questions_carry_the_october_2026_catalog(): void
    {
        $this->onAdmin(function () {
            $questions = ContentQuestion::where('content_role', 'ubi_official')->where('active', true)->get();
            $this->assertCount(130, $questions);

            foreach ($questions as $question) {
                $revision = $question->publishedRevision();
                $this->assertNotNull($revision, "UBI {$question->official_number} hat keine veröffentlichte Fassung.");
                $this->assertCount(4, $revision->answers);
                // "Richtig ist immer Antwort a)".
                $this->assertSame('A', $revision->correctAnswer()->answer_key);
            }

            // Frische Installation: alle 130 aus dem Katalog 2026-10. Nach
            // einem Update behalten die 65 inhaltlich unveränderten Fragen
            // ihre bisherige Revision (und damit deren Herkunftsangabe).
            $fromNewCatalog = $questions->filter(fn ($q) => $q->publishedRevision()->source_version === CatalogUpdateSeeder::UBI_2026_10)->count();
            $this->assertGreaterThanOrEqual(65, $fromNewCatalog);

            $q4 = ContentQuestion::where('content_role', 'ubi_official')->where('official_number', '4')->firstOrFail()->publishedRevision();
            $this->assertSame('Wie lange dürfen Testsendungen dauern? [4]', $q4->question_text);
            $this->assertSame('Maximal 10 Sekunden', $q4->correctAnswer()->answer_text);

            // Kapitelgrenze IV/V hat sich verschoben: 108 gehört jetzt zu "Sprechfunk".
            $q108 = ContentQuestion::where('content_role', 'ubi_official')->where('official_number', '108')->firstOrFail()->publishedRevision();
            $this->assertSame('Sprechfunk', $q108->subtopic);
        });
    }

    public function test_update_seeder_publishes_waiting_2026_revisions_without_inheriting_old_feedback(): void
    {
        [$old, $new] = $this->onAdmin(function () {
            $question = ContentQuestion::create([
                'content_id' => 'TEST-UBI-UPDATE-'.uniqid(),
                'official_number' => '9999',
                'content_role' => 'ubi_official',
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->fakeQuestionId = $question->id;

            $make = fn (int $no, string $status, string $version, ?string $feedback = null) => ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => $no,
                'question_type' => 'single_choice',
                'question_text' => "Fassung {$no}?",
                'feedback_correct' => $feedback,
                'source_version' => $version,
                'editorial_status' => $status,
                'rights_status' => 'review',
            ]);

            return [
                $make(1, 'published', '2026-09', 'Erklärung zur alten Antwort'),
                $make(2, 'review', CatalogUpdateSeeder::UBI_2026_10),
            ];
        });

        $this->onAdmin(fn () => (new CatalogUpdateSeeder)->run());
        // Zweiter Lauf: idempotent.
        $this->onAdmin(fn () => (new CatalogUpdateSeeder)->run());

        $this->onAdmin(function () use ($old, $new) {
            $this->assertSame('deprecated', $old->fresh()->editorial_status);
            $this->assertSame('published', $new->fresh()->editorial_status);
            $this->assertNull($new->fresh()->feedback_correct);
        });
    }

    public function test_update_seeder_resets_progress_when_the_question_content_changed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($tenant, $learner) {
            $question = ContentQuestion::create([
                'content_id' => 'TEST-UBI-UPDATE-'.uniqid(),
                'official_number' => '9997',
                'content_role' => 'ubi_official',
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->fakeQuestionId = $question->id;

            foreach ([[1, 'published', '2026-09', 'Alte Frage?'], [2, 'review', CatalogUpdateSeeder::UBI_2026_10, 'Ersetzte Frage?']] as [$no, $status, $version, $text]) {
                ContentQuestionRevision::create([
                    'question_id' => $question->id, 'revision_no' => $no, 'question_type' => 'single_choice',
                    'question_text' => $text, 'source_version' => $version, 'editorial_status' => $status, 'rights_status' => 'review',
                ]);
            }

            Progress::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                'attempt_count' => 3, 'correct_count' => 3, 'current_streak' => 3, 'mastery_score' => 1, 'learning_state' => 'gefestigt',
            ]);

            (new CatalogUpdateSeeder)->run();

            $this->assertSame(0, Progress::where('question_id', $question->id)->count());
        });
    }

    public function test_update_seeder_leaves_review_revisions_from_other_sources_alone(): void
    {
        $review = $this->onAdmin(function () {
            $question = ContentQuestion::create([
                'content_id' => 'TEST-UBI-UPDATE-'.uniqid(),
                'official_number' => '9998',
                'content_role' => 'ubi_official',
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->fakeQuestionId = $question->id;

            ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => 1, 'question_type' => 'single_choice',
                'question_text' => 'Alt?', 'source_version' => '2026-09', 'editorial_status' => 'published', 'rights_status' => 'review',
            ]);

            return ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => 2, 'question_type' => 'single_choice',
                'question_text' => 'Redaktionell geändert?', 'source_version' => '2026-09', 'editorial_status' => 'review', 'rights_status' => 'review',
            ]);
        });

        $this->onAdmin(fn () => (new CatalogUpdateSeeder)->run());

        $this->assertSame('review', $this->onAdmin(fn () => $review->fresh()->editorial_status));
    }

    public function test_ubi_has_the_12_official_practice_papers_with_22_questions_each(): void
    {
        $course = $this->existingCourse('UBI');

        $this->onAdmin(function () use ($course) {
            $papers = ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->with('paperQuestions.question')->get();
            $this->assertSame(range(1, 12), $papers->pluck('paper_number')->all());

            foreach ($papers as $paper) {
                $numbers = $paper->paperQuestions->sortBy('position')->map(fn ($pq) => (int) $pq->question->official_number)->values();
                $this->assertSame(range(1, 22), $paper->paperQuestions->pluck('position')->sort()->values()->all());
                $this->assertCount(22, $numbers->unique());
                $this->assertTrue($paper->paperQuestions->every(fn ($pq) => $pq->question->content_role === 'ubi_official'));

                // 5 Fragen aus Teil I, 5 aus II, 4 aus III, 4 aus IV, 4 aus V.
                $perPart = [
                    $numbers->filter(fn ($n) => $n <= 24)->count(),
                    $numbers->filter(fn ($n) => $n >= 25 && $n <= 54)->count(),
                    $numbers->filter(fn ($n) => $n >= 55 && $n <= 79)->count(),
                    $numbers->filter(fn ($n) => $n >= 80 && $n <= 108)->count(),
                    $numbers->filter(fn ($n) => $n >= 109)->count(),
                ];
                $this->assertSame([5, 5, 4, 4, 4], $perPart, "Bogen {$paper->paper_number}");
            }

            $first = $papers->first()->paperQuestions->sortBy('position')->map(fn ($pq) => (int) $pq->question->official_number)->values()->all();
            $this->assertSame([1, 5, 7, 13, 18, 26, 28, 36, 39, 50, 55, 62, 64, 73, 80, 84, 91, 101, 109, 111, 124, 130], $first);
        });
    }

    public function test_ubi_rule_set_is_verified_with_17_of_22_and_60_minutes(): void
    {
        $course = $this->existingCourse('UBI');

        $this->onAdmin(function () use ($course) {
            $ruleSet = ExamRuleSet::where('course_id', $course->id)->firstOrFail();
            $this->assertTrue($ruleSet->isVerified());
            $this->assertSame(3600, $ruleSet->time_limit_seconds);
            $this->assertEqualsWithDelta(17 / 22, $ruleSet->passing_rule['min_correct_ratio'], 1e-9);
            $this->assertSame([22], ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count')->all());
        });
    }

    public function test_exactly_17_of_22_passes_and_16_fails(): void
    {
        $this->assertTrue($this->takeFirstUbiPaperWithCorrectAnswers(17)->passed, '17 von 22 muss bestehen.');
        $this->assertFalse($this->takeFirstUbiPaperWithCorrectAnswers(16)->passed, '16 von 22 darf nicht bestehen.');
    }

    public function test_migration_switches_an_existing_placeholder_rule_set_to_the_official_values(): void
    {
        $course = $this->existingCourse('UBI');
        $migration = require database_path('migrations/2024_02_01_000474_verify_ubi_exam_rule_set.php');

        $this->onAdmin(function () use ($course, $migration) {
            $ruleSet = ExamRuleSet::where('course_id', $course->id)->firstOrFail();
            $original = $ruleSet->only(['time_limit_seconds', 'passing_rule', 'status', 'verified_by', 'verified_at']);
            $originalCounts = ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count', 'id');

            DB::beginTransaction();
            try {
                $ruleSet->update(['time_limit_seconds' => 3600, 'passing_rule' => ['min_correct_ratio' => 0.75], 'status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
                ExamBlueprint::where('rule_set_id', $ruleSet->id)->update(['question_count' => 10]);

                $migration->up();

                $ruleSet->refresh();
                $this->assertTrue($ruleSet->isVerified());
                $this->assertEqualsWithDelta(17 / 22, $ruleSet->passing_rule['min_correct_ratio'], 1e-9);
                $this->assertSame([22], ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count')->all());
            } finally {
                DB::rollBack();
            }

            $ruleSet->refresh();
            $this->assertSame($original['status'], $ruleSet->status);
            $this->assertSame($originalCounts->all(), ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count', 'id')->all());
        });
    }

    private function takeFirstUbiPaperWithCorrectAnswers(int $correct): ExamSession
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 1)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();

        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return [$session, $session->questions()->orderBy('position')->with('revision.answers')->get()];
        });
        $this->assertCount(22, $sessionQuestions);

        foreach ($sessionQuestions as $i => $sq) {
            $answer = $sq->revision->answers->firstWhere('is_correct', $i < $correct);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $answer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish")->assertRedirect();

        return $this->asTenant($tenant, fn () => $session->fresh());
    }
}
