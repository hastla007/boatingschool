<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use App\Models\Progress;
use Database\Seeders\CatalogUpdateSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Jede Frage aller Kataloge hat eine Erklärung für richtig und falsch
 * (sbf_see_binnen_fragen_feedback.csv für SBF Basis/See,
 * fragen_erklaerungen.csv für alle übrigen), und die 60 SRC-Antworten mit
 * mitimportierter PDF-Fußzeile sind bereinigt.
 */
class QuestionExplanationCoverageTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_every_catalogue_question_has_an_explanation(): void
    {
        $this->onAdmin(function () {
            foreach (['SBF_BASIS', 'SBF_SEE', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN', 'SRC', 'UBI', 'UBI_ERG', 'FKN', 'SKN'] as $code) {
                $missing = Module::where('code', $code)->firstOrFail()->questions
                    ->map(fn (ContentQuestion $q) => [$q->content_id, $q->publishedRevision()])
                    // Freie Navigations-Kartenaufgaben SBF See haben keine Antwortoptionen und kein Feedback.
                    ->reject(fn ($pair) => $pair[1]->answers->isEmpty())
                    ->filter(fn ($pair) => blank($pair[1]->feedback_correct) || blank($pair[1]->feedback_incorrect))
                    ->map(fn ($pair) => $pair[0]);

                $this->assertSame([], $missing->values()->all(), "Ohne Erklärung in {$code}");
            }
        });
    }

    public function test_explanations_do_not_repeat_the_verdict_shown_above_them(): void
    {
        $this->onAdmin(function () {
            $offending = ContentQuestionRevision::where('editorial_status', 'published')
                ->where(fn ($q) => $q->where('feedback_correct', 'like', 'Richtig.%')->orWhere('feedback_incorrect', 'like', 'Falsch.%')->orWhere('feedback_incorrect', 'like', 'Richtig ist:%'))
                ->count();

            $this->assertSame(0, $offending);

            // Kein Antwortrest vor der Erklärung (Antworten mit eigenen Anführungszeichen, z. B. SRC-0044).
            $fragments = ContentQuestionRevision::where('editorial_status', 'published')
                ->whereRaw("feedback_incorrect ~ '^[a-zäöüß“]'")
                ->count();
            $this->assertSame(0, $fragments);
        });
    }

    public function test_src_answers_carry_no_pdf_footer(): void
    {
        $this->onAdmin(function () {
            $footer = DB::table('content_answer')
                ->join('content_question_revision as r', 'r.id', '=', 'content_answer.revision_id')
                ->where('r.editorial_status', 'published')
                ->where('content_answer.answer_text', 'like', '%Gesamtfragenkatalog%')
                ->count();

            $this->assertSame(0, $footer);
        });
    }

    public function test_src_correction_keeps_feedback_and_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($tenant, $learner) {
            $question = ContentQuestion::create(['content_id' => 'TEST-SRC-KORR-'.uniqid(), 'official_number' => '9995', 'content_role' => 'src_official', 'language' => 'de-DE', 'active' => true]);
            $make = fn (int $no, string $status, string $version, ?string $feedback) => ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => $no, 'question_type' => 'single_choice', 'question_text' => 'Testfrage?',
                'feedback_correct' => $feedback, 'source_version' => $version, 'editorial_status' => $status, 'rights_status' => 'review',
            ]);
            $published = $make(1, 'published', '2026-09', 'Erklärung');
            $correction = $make(2, 'review', CatalogUpdateSeeder::SRC_2026_09_KORR, null);
            Progress::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                'attempt_count' => 3, 'correct_count' => 3, 'current_streak' => 3, 'mastery_score' => 1, 'learning_state' => 'gefestigt',
            ]);

            try {
                (new CatalogUpdateSeeder)->run();

                $this->assertSame('deprecated', $published->fresh()->editorial_status);
                $this->assertSame('published', $correction->fresh()->editorial_status);
                $this->assertSame('Erklärung', $correction->fresh()->feedback_correct);
                $this->assertSame(1, Progress::where('question_id', $question->id)->count());
            } finally {
                Progress::where('question_id', $question->id)->delete();
                $question->revisions()->delete();
                $question->delete();
            }
        });
    }

    public function test_radio_questions_no_longer_end_with_their_official_number(): void
    {
        $this->onAdmin(function () {
            $numbered = ContentQuestionRevision::where('editorial_status', 'published')
                ->whereHas('question', fn ($q) => $q->whereIn('content_role', ['src_official', 'ubi_official']))
                ->whereRaw("question_text ~ '\\[[0-9]+\\]\\s*$'")
                ->count();

            $this->assertSame(0, $numbered);
        });
    }

    public function test_second_correction_replaces_an_earlier_correction_and_keeps_feedback_and_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($tenant, $learner) {
            $question = ContentQuestion::create(['content_id' => 'TEST-SRC-KORR2-'.uniqid(), 'official_number' => '9994', 'content_role' => 'src_official', 'language' => 'de-DE', 'active' => true]);
            $make = fn (int $no, string $status, string $version, ?string $feedback) => ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => $no, 'question_type' => 'single_choice', 'question_text' => 'Testfrage? [53]',
                'feedback_correct' => $feedback, 'source_version' => $version, 'editorial_status' => $status, 'rights_status' => 'review',
            ]);
            $published = $make(1, 'published', CatalogUpdateSeeder::SRC_2026_09_KORR, 'Erklärung');
            $waiting = $make(2, 'review', '2026-09', null);
            $correction = $make(3, 'review', CatalogUpdateSeeder::SRC_2026_09_KORR2, null);
            Progress::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id,
                'attempt_count' => 3, 'correct_count' => 3, 'current_streak' => 3, 'mastery_score' => 1, 'learning_state' => 'gefestigt',
            ]);

            try {
                (new CatalogUpdateSeeder)->run();

                $this->assertSame('deprecated', $published->fresh()->editorial_status);
                $this->assertSame('deprecated', $waiting->fresh()->editorial_status);
                $this->assertSame('published', $correction->fresh()->editorial_status);
                $this->assertSame('Erklärung', $correction->fresh()->feedback_correct);
                $this->assertSame(1, Progress::where('question_id', $question->id)->count());
            } finally {
                Progress::where('question_id', $question->id)->delete();
                $question->revisions()->delete();
                $question->delete();
            }
        });
    }
}
