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
    public function test_src_ubi_combo_mirrors_the_src_ubi_and_ubi_addon_papers(): void
    {
        $course = fn (string $code) => CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail();
        $comboPapers = $course('SRC-UBI')->examPapers;
        $this->assertCount(36, $comboPapers);

        $groups = [
            [$comboPapers->filter(fn ($p) => $p->paper_number <= 12), $course('SRC'), 0],
            [$comboPapers->filter(fn ($p) => $p->paper_number >= 101 && $p->paper_number <= 112), $course('UBI'), 100],
            [$comboPapers->filter(fn ($p) => $p->paper_number >= 201), $course('ADD-UBI-TO-SRC'), 200],
        ];

        foreach ($groups as [$mirrored, $sourceCourse, $offset]) {
            $this->assertCount(12, $mirrored, "{$sourceCourse->code}-Bögen im Kombikurs");
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
        $this->assertSame(['label' => 'UBI-Ergänzung', 'displayNumber' => 3], ComboExamGroups::infoFor('SRC-UBI', 203));
    }
}
