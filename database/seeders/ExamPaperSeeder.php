<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * 15 feste Prüfungsbögen für SBF See, je 30 Fragen (7 allgemeine
 * Basisfragen + 23 kursspezifische Fragen), analog zu den amtlichen
 * Prüfungsbögen. Die Zuordnung Frage->Bogen ist hier eine deterministische
 * Verteilung aus dem echten, bereits importierten Fragenpool (kein
 * erfundener Fragentext) -- vor Produktivbetrieb durch die tatsächliche
 * amtliche Bogen-Zuordnung ersetzen.
 */
class ExamPaperSeeder extends Seeder
{
    private const PAPER_COUNT = 15;

    private const BASIS_PER_PAPER = 7;

    private const SPECIFIC_PER_PAPER = 23;

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $basisModule = Module::withoutGlobalScopes()->where('code', 'SBF_BASIS')->first();
        $specificModule = Module::withoutGlobalScopes()->where('code', 'SBF_SEE')->first();

        if (! $basisModule || ! $specificModule) {
            return;
        }

        $publishedQuestionIds = fn (Module $module) => $module->questions()
            ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
            ->orderBy('content_question.id')
            ->pluck('content_question.id')
            ->all();

        $basisIds = $publishedQuestionIds($basisModule);
        $specificIds = $publishedQuestionIds($specificModule);

        if (empty($basisIds) || empty($specificIds)) {
            return;
        }

        for ($paperNumber = 1; $paperNumber <= self::PAPER_COUNT; $paperNumber++) {
            $paper = ExamPaper::create([
                'course_id' => $course->id,
                'paper_number' => $paperNumber,
                'sort_order' => $paperNumber,
            ]);

            $position = 1;

            for ($i = 0; $i < self::BASIS_PER_PAPER; $i++) {
                $questionId = $basisIds[(($paperNumber - 1) * self::BASIS_PER_PAPER + $i) % count($basisIds)];
                ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $questionId, 'position' => $position++]);
            }

            for ($i = 0; $i < self::SPECIFIC_PER_PAPER; $i++) {
                $questionId = $specificIds[(($paperNumber - 1) * self::SPECIFIC_PER_PAPER + $i) % count($specificIds)];
                ExamPaperQuestion::create(['exam_paper_id' => $paper->id, 'question_id' => $questionId, 'position' => $position++]);
            }
        }
    }
}
