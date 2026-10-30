<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bug-Review (SRC & UBI Kombikurs: 94 Fragen der UBI-Prüfungsbögen gehören
 * nicht zum Kurs): UbiExamPaperSeeder und SrcUbiComboExamPaperSeeder (siehe
 * dort) sind zwar exists()-guard-idempotent, kamen aber erst nachträglich zu
 * DatabaseSeeder hinzu (Aufgabe "UBI: vorläufige Prüfungsbögen bauen") --
 * eine bereits provisionierte Installation erreichen sie nur, wenn db:seed
 * dort anschließend tatsächlich erneut läuft (siehe Bug-Review B5: "Seeds
 * nicht idempotent"). Blieb das aus, hatte der UBI Vollkurs überhaupt keine
 * Prüfungsbögen und der SRC-UBI-Kombikurs keine der UBI-Bögen 101-106 --
 * fast alle 130 ubi_official-Fragen tauchten dadurch in keinem Prüfungsbogen
 * des Kombikurses auf ("gehören nicht zum Kurs"), obwohl sie über das
 * UBI-Modul technisch längst Kursinhalt waren (Favoriten-Stern/"Falsche
 * Fragen erneut lernen" hängen aber an exam_paper_question, nicht am Modul).
 * Diese Migration holt beide Seeder-Ergebnisse direkt nach, statt sich auf
 * einen künftigen erneuten db:seed-Lauf zu verlassen -- dieselben
 * exists()-Prüfungen wie in den Seedern machen sie ein No-op, sobald die
 * Bögen (z. B. durch einen regulären db:seed-Lauf) bereits vorhanden sind.
 */
return new class extends Migration
{
    /** @var array<int, array{0:int,1:int}> Jeweils [von, bis] Fragenkatalog-Nr. (inklusive) pro Bogen. */
    private const UBI_RANGES = [
        1 => [1, 22],
        2 => [23, 44],
        3 => [45, 66],
        4 => [67, 88],
        5 => [89, 110],
    ];

    public function up(): void
    {
        $this->backfillUbiExamPapers();
        $this->mirrorIntoSrcUbiCombo();
    }

    public function down(): void
    {
        // Datenkorrektur-Migration (siehe X1/Y1/Y6/Y9-Muster) -- kein
        // sinnvoller Rollback, da ein Zurücksetzen die vorläufigen
        // UBI-Prüfungsbögen wieder entfernen würde.
    }

    private function backfillUbiExamPapers(): void
    {
        $course = DB::table('course_definition')->where('code', 'UBI')->whereNull('tenant_id')->first();

        if (! $course || DB::table('exam_paper')->where('course_id', $course->id)->exists()) {
            return;
        }

        $questionsByNumber = DB::table('content_question')
            ->where('content_role', 'ubi_official')
            ->get(['id', 'official_number'])
            ->keyBy(fn ($question) => (int) $question->official_number);

        foreach ($this->ubiPaperSequences() as $paperNumber => $officialNumbers) {
            $paperId = (string) Str::uuid();
            DB::table('exam_paper')->insert([
                'id' => $paperId,
                'course_id' => $course->id,
                'paper_number' => $paperNumber,
                'sort_order' => $paperNumber,
                'created_at' => now(),
            ]);

            foreach ($officialNumbers as $position => $officialNumber) {
                $question = $questionsByNumber->get($officialNumber);

                if (! $question) {
                    continue;
                }

                DB::table('exam_paper_question')->insert([
                    'exam_paper_id' => $paperId,
                    'question_id' => $question->id,
                    'position' => $position + 1,
                ]);
            }
        }
    }

    /** @return array<int, array<int, int>> Bogen-Nummer => Liste von Fragenkatalog-Nr. in Reihenfolge. */
    private function ubiPaperSequences(): array
    {
        $sequences = [];

        foreach (self::UBI_RANGES as $paperNumber => [$from, $to]) {
            $sequences[$paperNumber] = range($from, $to);
        }

        // Bogen 6: die letzten 20 Fragen (111-130) plus die ersten 2 (1-2)
        // wiederverwendet, um auf die vollen 22 Fragen pro Bogen zu kommen.
        $sequences[6] = array_merge(range(111, 130), range(1, 2));

        return $sequences;
    }

    private function mirrorIntoSrcUbiCombo(): void
    {
        $combo = DB::table('course_definition')->where('code', 'SRC-UBI')->whereNull('tenant_id')->first();

        if (! $combo) {
            return;
        }

        $sources = [
            ['course' => 'SRC', 'offset' => 0],
            ['course' => 'UBI', 'offset' => 100],
        ];

        foreach ($sources as $source) {
            // Idempotent pro Teilkurs: fehlt der Nummernblock dieses
            // Teilkurses im Kombikurs noch, wird nur er nachgeholt (nicht
            // beide erneut, falls einer schon per db:seed gespiegelt wurde).
            $alreadyMirrored = DB::table('exam_paper')
                ->where('course_id', $combo->id)
                ->where('paper_number', '>=', $source['offset'] + 1)
                ->where('paper_number', '<=', $source['offset'] + 99)
                ->exists();

            if ($alreadyMirrored) {
                continue;
            }

            $sourceCourse = DB::table('course_definition')->where('code', $source['course'])->whereNull('tenant_id')->first();

            if (! $sourceCourse) {
                continue;
            }

            $sourcePapers = DB::table('exam_paper')->where('course_id', $sourceCourse->id)->get();

            foreach ($sourcePapers as $sourcePaper) {
                $paperId = (string) Str::uuid();
                DB::table('exam_paper')->insert([
                    'id' => $paperId,
                    'course_id' => $combo->id,
                    'paper_number' => $sourcePaper->paper_number + $source['offset'],
                    'sort_order' => $sourcePaper->paper_number + $source['offset'],
                    'created_at' => now(),
                ]);

                $sourceQuestions = DB::table('exam_paper_question')->where('exam_paper_id', $sourcePaper->id)->get();

                foreach ($sourceQuestions as $sourceQuestion) {
                    DB::table('exam_paper_question')->insert([
                        'exam_paper_id' => $paperId,
                        'question_id' => $sourceQuestion->question_id,
                        'position' => $sourceQuestion->position,
                    ]);
                }
            }
        }
    }
};
