<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\TestCase;

/**
 * Prüft die von ExamPaperSeeder aus database/data/sbf_see_pruefungsboegen.csv
 * geladenen amtlichen Prüfungsbögen: korrekte Anzahl/Größe sowie dass die
 * Fragenkatalog-Nr. eindeutig über die Fragenfamilie (Tags-Spalte ->
 * content_role) aufgelöst wird, statt versehentlich eine gleichnummerige
 * Frage aus einem anderen Katalog (SRC/UBI/Binnen) zu treffen.
 */
class ExamPaperSeederTest extends TestCase
{
    public function test_sbf_see_has_15_official_exam_papers_with_30_questions_each(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();

        $papers = $course->examPapers;

        $this->assertCount(15, $papers);
        $papers->each(fn ($paper) => $this->assertSame(30, $paper->paperQuestions()->count(), "Bogen {$paper->paper_number}"));
    }

    public function test_paper_questions_resolve_to_the_correct_question_family(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();
        $paper = $course->examPapers->firstOrFail();

        $roles = $paper->paperQuestions->map(fn ($pq) => $pq->question->content_role)->countBy();

        $this->assertSame(7, $roles->get('shared_basis', 0));
        $this->assertSame(23, $roles->get('see_specific', 0));
    }

    public function test_questions_referencing_images_in_the_source_data_have_media_attached(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();
        $paper = $course->examPapers->firstOrFail();

        $withMedia = $paper->paperQuestions
            ->map(fn ($pq) => $pq->question->publishedRevision())
            ->filter(fn ($revision) => $revision && $revision->media()->exists());

        $this->assertGreaterThan(0, $withMedia->count());
    }
}
