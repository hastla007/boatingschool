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

    /**
     * Bug-Review (AA2, überholt): diese Assertion erwartete früher 6
     * UBI-Bögen und dass jede UBI-Bogenfrage über das Modul UBI zum
     * Kombikurs gehört. Seit bf57170 lernt der Kombikurs bewusst nur SRC und
     * die UBI-Ergänzung (259 statt 310 Fragen), spiegelt aber weiterhin die
     * zwölf UBI-Vollkurs-Bögen 101-112 und die zwölf Ergänzungsbögen
     * 201-212. Ein Teil der UBI-Bogenfragen liegt damit gewollt außerhalb
     * des Modul-Scopes -- Favoriten-Stern und "Falsche Fragen erneut
     * lernen" behandeln die Fragen der Prüfungsbögen eines Kurses deshalb
     * als Kursinhalt (siehe SrcUbiPaperQuestionsBelongToCourseTest).
     */
    public function test_src_ubi_combo_learns_src_and_ubi_addon_but_keeps_the_full_ubi_papers(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->firstOrFail();

        $this->assertSame(['SRC', 'UBI_ERG'], $combo->modules()->pluck('code')->all());

        $ubiPapers = $combo->examPapers->filter(fn ($p) => $p->paper_number >= 101 && $p->paper_number <= 112);
        $addonPapers = $combo->examPapers->filter(fn ($p) => $p->paper_number >= 201 && $p->paper_number <= 212);
        $this->assertCount(12, $ubiPapers);
        $this->assertCount(12, $addonPapers);

        $moduleQuestionIds = $combo->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        $ubiPaperQuestionIds = $ubiPapers->flatMap(fn ($p) => $p->paperQuestions->pluck('question_id'))->unique();
        $addonPaperQuestionIds = $addonPapers->flatMap(fn ($p) => $p->paperQuestions->pluck('question_id'))->unique();

        $this->assertCount(130, $ubiPaperQuestionIds, 'Alle 130 ubi_official-Fragen müssen über die 12 UBI-Bögen referenziert sein.');
        $this->assertCount(0, $addonPaperQuestionIds->diff($moduleQuestionIds), 'Die Ergänzungsbögen bestehen nur aus Fragen des Moduls UBI_ERG.');
        $this->assertNotEmpty($ubiPaperQuestionIds->diff($moduleQuestionIds), 'Die UBI-Vollkurs-Bögen enthalten bewusst auch Fragen außerhalb von SRC/UBI_ERG.');
    }
}
