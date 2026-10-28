<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Bug-Review (README hat falsche Zahlen): zwei Angaben im README waren
 * veraltet -- die Excel-Zeilenstatistik ("821 importierbar -- 17 Zeilen
 * sind freie Kartenaufgaben") stimmte nicht mehr mit dem tatsächlichen
 * Inhalt von zentrale_fragenbasis.xlsx überein (tatsächlich: 823/15, siehe
 * import-Kriterium "Nicht alle vier Antworten (A-D) vorhanden" in
 * ImportContentQuestions::importRow()), und "inzwischen über 25"
 * mandantengebundene Tabellen traf nicht mehr zu, da es inzwischen genau
 * (nicht "über") 25 sind.
 */
class ReadmeAccuracyTest extends TestCase
{
    public function test_the_readme_states_the_correct_importable_row_count(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $path = base_path('database/data/zentrale_fragenbasis.xlsx');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Central Question Pool') ?? $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        $totalDataRows = $highestRow - 1;
        $skipped = 0;
        for ($r = 2; $r <= $highestRow; $r++) {
            $answers = [
                trim((string) $sheet->getCell('F'.$r)->getValue()),
                trim((string) $sheet->getCell('G'.$r)->getValue()),
                trim((string) $sheet->getCell('H'.$r)->getValue()),
                trim((string) $sheet->getCell('I'.$r)->getValue()),
            ];
            if (in_array('', $answers, true)) {
                $skipped++;
            }
        }
        $importable = $totalDataRows - $skipped;

        $this->assertStringContainsString(
            "{$totalDataRows} Zeilen, davon\n{$importable} importierbar",
            $readme
        );
        $this->assertStringContainsString("{$skipped} Zeilen sind freie Kartenaufgaben", $readme);
    }

    public function test_the_readme_states_the_correct_tenant_scoped_table_count(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $actualCount = DB::connection('pgsql_admin')->selectOne(
            "SELECT count(*) AS count FROM information_schema.columns WHERE column_name = 'tenant_id' AND table_schema = 'public'"
        )->count;

        $this->assertStringContainsString("Mandantengebundene Tabellen\n  (inzwischen {$actualCount},", $readme);
    }
}
