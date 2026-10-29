<?php

namespace Tests\Feature;

use App\Models\ExamBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\Module;
use Database\Seeders\UbiErgaenzungSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ergänzungsprüfung UBI für Inhaber eines Seefunkzeugnisses, Stand
 * Oktober 2026: 79 Fragen aus dem UBI-Gesamtfragenkatalog (Modul UBI_ERG)
 * und 12 Übungsfragebögen à 10 Fragen für ADD-UBI-TO-SRC.
 */
class UbiErgaenzungTest extends TestCase
{
    use InteractsWithTenants;

    public function test_module_contains_exactly_the_79_questions_of_the_supplementary_catalog(): void
    {
        $expected = array_values(include database_path('data/ubi_ergaenzung_katalog.php'));
        sort($expected);

        $this->onAdmin(function () use ($expected) {
            $questions = Module::where('code', 'UBI_ERG')->firstOrFail()->questions()->get();

            $this->assertCount(79, $questions);
            $this->assertTrue($questions->every(fn ($q) => $q->content_role === 'ubi_official'));
            $this->assertSame($expected, $questions->map(fn ($q) => (int) $q->official_number)->sort()->values()->all());
        });
    }

    public function test_supplementary_course_uses_only_the_subset_module(): void
    {
        $course = $this->existingCourse('ADD-UBI-TO-SRC');

        $this->onAdmin(function () use ($course) {
            $this->assertSame(['UBI_ERG'], $course->modules()->pluck('code')->all());

            $ruleSet = ExamRuleSet::where('course_id', $course->id)->firstOrFail();
            $blueprints = ExamBlueprint::where('rule_set_id', $ruleSet->id)->with('module')->get();
            $this->assertSame(['UBI_ERG'], $blueprints->pluck('module.code')->all());
            $this->assertSame([10], $blueprints->pluck('question_count')->all());
        });
    }

    public function test_supplementary_course_has_12_practice_papers_with_10_questions_each(): void
    {
        $course = $this->existingCourse('ADD-UBI-TO-SRC');

        $this->onAdmin(function () use ($course) {
            $subset = Module::where('code', 'UBI_ERG')->firstOrFail()->questions()->pluck('content_question.id')->all();
            $papers = ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->with('paperQuestions.question')->get();
            $this->assertSame(range(1, 12), $papers->pluck('paper_number')->all());

            foreach ($papers as $paper) {
                $pqs = $paper->paperQuestions->sortBy('position')->values();
                $this->assertSame(range(1, 10), $pqs->pluck('position')->all());
                $this->assertEmpty(array_diff($pqs->pluck('question_id')->all(), $subset), "Bogen {$paper->paper_number}");

                $numbers = $pqs->map(fn ($pq) => (int) $pq->question->official_number);
                $perPart = [
                    $numbers->filter(fn ($n) => $n <= 24)->count(),
                    $numbers->filter(fn ($n) => $n >= 25 && $n <= 54)->count(),
                    $numbers->filter(fn ($n) => $n >= 55 && $n <= 79)->count(),
                    $numbers->filter(fn ($n) => $n >= 80 && $n <= 108)->count(),
                    $numbers->filter(fn ($n) => $n >= 109)->count(),
                ];
                $this->assertSame([2, 2, 2, 2, 2], $perPart, "Bogen {$paper->paper_number}");
            }

            // Lösungsbogen 7 nennt [26], Übungsbogen und Fragenverteilung 36.
            $bogen7 = $papers->firstWhere('paper_number', 7)->paperQuestions->sortBy('position')->map(fn ($pq) => (int) $pq->question->official_number)->values()->all();
            $this->assertSame([3, 7, 36, 44, 55, 57, 81, 93, 111, 122], $bogen7);
        });
    }

    public function test_seeder_moves_an_existing_installation_from_the_full_module_to_the_subset(): void
    {
        $course = $this->existingCourse('ADD-UBI-TO-SRC');

        $this->onAdmin(function () use ($course) {
            $full = Module::where('code', 'UBI')->firstOrFail();
            $subset = Module::where('code', 'UBI_ERG')->firstOrFail();
            $ruleSet = ExamRuleSet::where('course_id', $course->id)->firstOrFail();

            DB::beginTransaction();
            try {
                // Stand vor Oktober 2026: Kurs hängt am vollständigen UBI-Modul.
                $course->modules()->detach($subset->id);
                $course->modules()->attach($full->id, ['sort_order' => 1, 'required' => true]);
                ExamBlueprint::where('rule_set_id', $ruleSet->id)->delete();
                ExamBlueprint::create(['rule_set_id' => $ruleSet->id, 'module_id' => $full->id, 'question_count' => 10]);

                (new UbiErgaenzungSeeder)->run();
                (new UbiErgaenzungSeeder)->run();

                $this->assertSame(['UBI_ERG'], $course->modules()->pluck('code')->all());
                $this->assertSame(0, ExamBlueprint::where('rule_set_id', $ruleSet->id)->where('module_id', $full->id)->count());
                $this->assertSame(79, $subset->questions()->count());
            } finally {
                DB::rollBack();
            }
        });
    }
}
