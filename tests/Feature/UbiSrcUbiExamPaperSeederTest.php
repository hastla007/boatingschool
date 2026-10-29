<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamRuleSet;
use App\Support\ComboExamGroups;
use Tests\TestCase;

/**
 * Spiegelung der SRC- und UBI-Prüfungsbögen im Kombikurs SRC-UBI
 * (SrcUbiComboExamPaperSeeder). Die UBI-Bögen selbst prüft
 * UbiCatalog2026Test.
 */
class UbiSrcUbiExamPaperSeederTest extends TestCase
{
    public function test_src_ubi_combo_mirrors_all_12_src_papers_and_all_12_ubi_papers(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->firstOrFail();
        $src = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC')->whereNull('tenant_id')->firstOrFail();
        $ubi = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->firstOrFail();

        $comboPapers = $combo->examPapers;
        $this->assertCount(24, $comboPapers);

        $srcMirrored = $comboPapers->filter(fn ($p) => $p->paper_number <= 12);
        $ubiMirrored = $comboPapers->filter(fn ($p) => $p->paper_number >= 101);

        $this->assertCount(12, $srcMirrored);
        $this->assertCount(12, $ubiMirrored);

        foreach ([[$srcMirrored, $src, 0], [$ubiMirrored, $ubi, 100]] as [$mirrored, $sourceCourse, $offset]) {
            foreach ($mirrored as $paper) {
                $sourcePaper = $sourceCourse->examPapers->firstWhere('paper_number', $paper->paper_number - $offset);
                $this->assertNotNull($sourcePaper, "Kein {$sourceCourse->code}-Quellbogen für Nr. {$paper->paper_number}");
                $this->assertSame(
                    $sourcePaper->paperQuestions->sortBy('position')->pluck('question_id')->values()->all(),
                    $paper->paperQuestions->sortBy('position')->pluck('question_id')->values()->all(),
                    "Bogen {$paper->paper_number} wurde nicht korrekt gespiegelt."
                );
            }
        }
    }

    public function test_src_ubi_combo_remains_unverified_because_the_parts_have_different_thresholds(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->firstOrFail();
        $ruleSet = ExamRuleSet::where('course_id', $combo->id)->firstOrFail();

        $this->assertFalse($ruleSet->isVerified());
    }

    public function test_combo_exam_groups_resolves_src_ubi_paper_labels_and_display_numbers(): void
    {
        $this->assertSame(['label' => 'SRC', 'displayNumber' => 5], ComboExamGroups::infoFor('SRC-UBI', 5));
        $this->assertSame(['label' => 'UBI', 'displayNumber' => 3], ComboExamGroups::infoFor('SRC-UBI', 103));
        $this->assertSame(['label' => 'UBI', 'displayNumber' => 12], ComboExamGroups::infoFor('SRC-UBI', 112));
        $this->assertNull(ComboExamGroups::infoFor('SRC-UBI', 50));
        $this->assertNull(ComboExamGroups::infoFor('SRC-UBI', 200));
    }
}
