<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * VORLÄUFIGE, NICHT AMTLICHE Prüfungsbögen für den UBI Vollkurs (UBI).
 *
 * Anders als bei SRC (SrcExamPaperSeeder, Quelle: WSV-Fachstelle Anlage 2a)
 * oder FKN/SKN (fkn_pruefungsboegen.php, Quelle: PANord) liegt für UBI
 * keine amtliche Zusammenstellung vor, welche Fragenkatalog-Nr. in welchen
 * Bogen gehört -- das einzige UBI-bezogene Blatt in der zentralen
 * Fragenbasis ("SRC-UBI Mapping") ist ausdrücklich nur ein automatischer
 * Ähnlichkeitsvergleich zu SRC-Fragen ("keine amtliche Anrechnung") und
 * keine Bogen-Zuordnung.
 *
 * Auf ausdrücklichen Nutzerwunsch werden deshalb vorläufige, unverifizierte
 * Bögen aus den 130 vorhandenen ubi_official-Fragen (Fragenkatalog-Nr. 1-130,
 * lückenlos, keine Duplikate) gebaut: 6 Bögen á 22 Fragen (Bestehensgrenze
 * 17/22, 60 Minuten -- siehe ExamRuleSetSeeder), sequentiell nach
 * Fragenkatalog-Nr. Bogen 6 hat nur noch 20 übrige Fragen (111-130) und
 * wiederholt deshalb die Fragen 1-2, um auf 22 zu kommen -- eine Frage in
 * mehreren Bögen wiederzuverwenden ist bei amtlichen Prüfungsbögen üblich
 * (siehe z. B. fkn_pruefungsboegen.php, Frage 12/24 in mehreren Bögen).
 *
 * ExamRuleSetSeeder lässt UBI deshalb bewusst NICHT in $verifiedCourses:
 * die Bestehensgrenze (17/22) ist eine echte, vom Nutzer belegte Zahl, die
 * Bogen-ZUSAMMENSETZUNG selbst aber nicht amtlich geprüft. Ein echter,
 * zählender Prüfungsversuch (ExamController::startPaper) bleibt daher
 * weiterhin gesperrt, bis die echte amtliche Zusammenstellung vorliegt und
 * diese Bögen entsprechend ersetzt werden.
 */
class UbiExamPaperSeeder extends Seeder
{
    /** @var array<int, array{0:int,1:int}> Jeweils [von, bis] Fragenkatalog-Nr. (inklusive) pro Bogen. */
    private const RANGES = [
        1 => [1, 22],
        2 => [23, 44],
        3 => [45, 66],
        4 => [67, 88],
        5 => [89, 110],
    ];

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $questionsByNumber = ContentQuestion::where('content_role', 'ubi_official')
            ->get()
            ->keyBy(fn ($question) => (int) $question->official_number);

        foreach ($this->paperNumberSequences() as $paperNumber => $officialNumbers) {
            $paper = ExamPaper::create([
                'course_id' => $course->id,
                'paper_number' => $paperNumber,
                'sort_order' => $paperNumber,
            ]);

            foreach ($officialNumbers as $position => $officialNumber) {
                $question = $questionsByNumber->get($officialNumber);

                if (! $question) {
                    continue;
                }

                ExamPaperQuestion::create([
                    'exam_paper_id' => $paper->id,
                    'question_id' => $question->id,
                    'position' => $position + 1,
                ]);
            }
        }
    }

    /** @return array<int, array<int, int>> Bogen-Nummer => Liste von Fragenkatalog-Nr. in Reihenfolge. */
    private function paperNumberSequences(): array
    {
        $sequences = [];

        foreach (self::RANGES as $paperNumber => [$from, $to]) {
            $sequences[$paperNumber] = range($from, $to);
        }

        // Bogen 6: die letzten 20 Fragen (111-130) plus die ersten 2 (1-2)
        // wiederverwendet, um auf die vollen 22 Fragen pro Bogen zu kommen.
        $sequences[6] = array_merge(range(111, 130), range(1, 2));

        return $sequences;
    }
}
