<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Die 12 amtlichen Übungsfragebögen für den UBI Vollkurs (UBI), je 22
 * Fragen (Bestehensgrenze 17/22, Zeitlimit 60 Minuten -- siehe
 * ExamRuleSetSeeder). Quelle: ABVT Koblenz, "Fragenverteilung / Prüfung
 * UKW-Sprechfunkzeugnis (UBI)", Stand Oktober 2026 (ubi_uebungsboegen.csv,
 * abgeglichen mit den Lösungsbögen derselben Ausgabe): je Bogen 5 Fragen
 * aus Teil I, 5 aus II, 4 aus III, 4 aus IV und 4 aus V des
 * Gesamtfragenkatalogs. Die Antwortreihenfolge der gedruckten Bögen wird
 * nicht übernommen, die Prüfungssimulation mischt die Antworten selbst.
 * Die praktische Prüfung (Sprechfunkverkehr am Gerät) ist nicht Teil
 * dieser Online-Simulation.
 *
 * Alle 12 Bögen entstehen in einer Transaktion und nur, wenn alle 264
 * Katalognummern auflösbar sind -- ein Bogen mit weniger als 22 Fragen
 * würde die Bestehensgrenze verfälschen.
 */
class UbiExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'UBI')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/ubi_uebungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $questionIds = ContentQuestion::where('content_role', 'ubi_official')->pluck('id', 'official_number');
        $rows = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $officialNumber = trim($row['Fragenkatalog-Nr.']);

            if (! isset($questionIds[$officialNumber])) {
                $this->command?->warn("UBI-Übungsbögen übersprungen: Katalogfrage {$officialNumber} fehlt.");

                return;
            }

            $rows[] = [
                'paper_number' => (int) $row['Pruefungsbogen'],
                'position' => (int) $row['Position im Bogen'],
                'question_id' => $questionIds[$officialNumber],
            ];
        }

        DB::transaction(function () use ($course, $rows) {
            $papers = [];

            foreach ($rows as $row) {
                $paper = $papers[$row['paper_number']] ??= ExamPaper::create([
                    'course_id' => $course->id,
                    'paper_number' => $row['paper_number'],
                    'sort_order' => $row['paper_number'],
                ]);

                ExamPaperQuestion::create([
                    'exam_paper_id' => $paper->id,
                    'question_id' => $row['question_id'],
                    'position' => $row['position'],
                ]);
            }
        });
    }

    /** @return iterable<array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }
}
