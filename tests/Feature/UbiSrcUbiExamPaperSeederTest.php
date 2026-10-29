<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamRuleSet;
use App\Support\ComboExamGroups;
use Tests\TestCase;

/**
 * Prüft die neuen, vorläufigen/nicht amtlichen UBI-Prüfungsbögen
 * (UbiExamPaperSeeder) und deren Spiegelung im Kombikurs SRC-UBI
 * (SrcUbiComboExamPaperSeeder) -- siehe deren Docblocks für den Hintergrund
 * (keine amtliche Bogen-Zusammenstellung für UBI im Gegensatz zu SRC/FKN).
 */
class UbiSrcUbiExamPaperSeederTest extends TestCase
{
    public function test_ubi_has_six_provisional_exam_papers_with_22_questions_each(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->firstOrFail();

        $papers = $course->examPapers;

        $this->assertCount(6, $papers);
        $papers->each(fn ($paper) => $this->assertSame(22, $paper->paperQuestions()->count(), "Bogen {$paper->paper_number}"));
    }

    public function test_ubi_paper_questions_resolve_to_the_ubi_official_catalog_and_the_last_paper_wraps_around(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->firstOrFail();

        $officialNumbersByPaper = $course->examPapers->mapWithKeys(
            fn ($paper) => [$paper->paper_number => $paper->paperQuestions->map(fn ($pq) => (int) $pq->question->official_number)->all()]
        );

        $this->assertSame(range(1, 22), $officialNumbersByPaper[1]);
        $this->assertSame(range(89, 110), $officialNumbersByPaper[5]);
        $this->assertSame([...range(111, 130), 1, 2], $officialNumbersByPaper[6]);

        $course->examPapers->each(function ($paper) {
            $paper->paperQuestions->each(
                fn ($pq) => $this->assertSame('ubi_official', $pq->question->content_role, "Bogen {$paper->paper_number}")
            );
        });
    }

    public function test_ubi_passing_rule_is_17_of_22_but_remains_unverified(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->firstOrFail();
        $ruleSet = ExamRuleSet::where('course_id', $course->id)->firstOrFail();

        $this->assertEqualsWithDelta(17 / 22, $ruleSet->passing_rule['min_correct_ratio'], 0.0001);
        $this->assertSame(3600, $ruleSet->time_limit_seconds);
        $this->assertFalse(
            $ruleSet->isVerified(),
            'UBI darf nicht als verifiziert gelten, solange die Bogen-Zusammensetzung nicht amtlich geprüft ist.'
        );
    }

    public function test_src_ubi_combo_mirrors_all_12_src_papers_and_all_6_ubi_papers(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->firstOrFail();
        $src = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC')->whereNull('tenant_id')->firstOrFail();
        $ubi = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->firstOrFail();

        $comboPapers = $combo->examPapers;
        $this->assertCount(18, $comboPapers);

        $srcMirrored = $comboPapers->filter(fn ($p) => $p->paper_number <= 12);
        $ubiMirrored = $comboPapers->filter(fn ($p) => $p->paper_number >= 101);

        $this->assertCount(12, $srcMirrored);
        $this->assertCount(6, $ubiMirrored);

        foreach ($srcMirrored as $paper) {
            $sourcePaper = $src->examPapers->firstWhere('paper_number', $paper->paper_number);
            $this->assertNotNull($sourcePaper, "Kein SRC-Quellbogen für Nr. {$paper->paper_number}");
            $this->assertSame(
                $sourcePaper->paperQuestions->pluck('question_id')->all(),
                $paper->paperQuestions->pluck('question_id')->all(),
                "SRC-Bogen {$paper->paper_number} wurde nicht korrekt gespiegelt."
            );
        }

        foreach ($ubiMirrored as $paper) {
            $sourcePaper = $ubi->examPapers->firstWhere('paper_number', $paper->paper_number - 100);
            $this->assertNotNull($sourcePaper, 'Kein UBI-Quellbogen für Nr. '.($paper->paper_number - 100));
            $this->assertSame(
                $sourcePaper->paperQuestions->pluck('question_id')->all(),
                $paper->paperQuestions->pluck('question_id')->all(),
                "UBI-Bogen {$paper->paper_number} wurde nicht korrekt gespiegelt."
            );
        }
    }

    public function test_src_ubi_combo_also_remains_unverified(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->firstOrFail();
        $ruleSet = ExamRuleSet::where('course_id', $combo->id)->firstOrFail();

        $this->assertFalse(
            $ruleSet->isVerified(),
            'SRC-UBI darf nicht als verifiziert gelten, solange die UBI-Hälfte nicht amtlich geprüft ist.'
        );
    }

    public function test_combo_exam_groups_resolves_src_ubi_paper_labels_and_display_numbers(): void
    {
        $this->assertSame(['label' => 'SRC', 'displayNumber' => 5], ComboExamGroups::infoFor('SRC-UBI', 5));
        $this->assertSame(['label' => 'UBI', 'displayNumber' => 3], ComboExamGroups::infoFor('SRC-UBI', 103));
        $this->assertNull(ComboExamGroups::infoFor('SRC-UBI', 50));
        $this->assertNull(ComboExamGroups::infoFor('SRC-UBI', 200));
    }
}
