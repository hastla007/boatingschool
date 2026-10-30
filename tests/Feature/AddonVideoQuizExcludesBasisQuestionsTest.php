<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\TestCase;

/**
 * Bug-Review (Ergänzungskurs stellt Basisfragen außerhalb des Prüfungs-
 * umfangs): siehe AddBinToSeeVideoCourseSeeder/AddSeeToBinVideoCourseSeeder
 * und die begleitende Migration
 * 2024_02_01_000480_remove_out_of_scope_basis_questions_from_addon_video_quizzes.
 * Die Ergänzungskurse "Binnen → See" und "See → Binnen" prüfen laut
 * Prüfungsordnung keine Basis-Fragen mehr (die Basis wurde bereits beim
 * jeweiligen Voraussetzungskurs geprüft).
 */
class AddonVideoQuizExcludesBasisQuestionsTest extends TestCase
{
    public function test_add_bin_to_see_video_quizzes_contain_no_basis_questions(): void
    {
        $this->assertNoBasisQuestions('ADD-BIN-TO-SEE');
    }

    public function test_add_see_to_bin_video_quizzes_contain_no_basis_questions(): void
    {
        $this->assertNoBasisQuestions('ADD-SEE-TO-BIN');
    }

    private function assertNoBasisQuestions(string $courseCode): void
    {
        $addon = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        $totalQuestions = 0;
        $basisQuestions = 0;

        foreach ($addon->videoModules()->with('lessons.questions.modules')->get() as $module) {
            foreach ($module->lessons as $lesson) {
                foreach ($lesson->questions as $question) {
                    $totalQuestions++;
                    if ($question->modules->pluck('code')->contains('SBF_BASIS')) {
                        $basisQuestions++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $totalQuestions, "{$courseCode} sollte weiterhin eigene Video-Quiz-Fragen haben.");
        $this->assertSame(0, $basisQuestions, "{$courseCode} sollte keine SBF_BASIS-Fragen in seinen Video-Quizzen haben.");
    }
}
