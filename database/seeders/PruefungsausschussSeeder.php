<?php

namespace Database\Seeders;

use App\Models\Pruefungsausschuss;
use Illuminate\Database\Seeder;

/**
 * Importiert die bundesweite DMYV-Liste der Prüfungsausschüsse/-zentren
 * aus database/data/pruefungsausschuesse.csv -- fest definierte, platt-
 * formweite Adressen (siehe Pruefungsausschuss-Model), die die
 * Bootsschule im Orte-Tab optional einem Praxisort zuordnen kann.
 */
class PruefungsausschussSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/pruefungsausschuesse.csv');

        if (! file_exists($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        // UTF-8-BOM am Dateianfang abstreifen, sonst landet es im ersten
        // Spaltennamen ("kommission") und bricht die Header-Zuordnung.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        $sortOrder = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $data = array_combine($header, $row);

            // Bug-Review (Seeder überschreibt Superadmin-Änderungen): dieser
            // Seeder läuft in Produktion erneut, sobald die DMYV-Liste
            // aktualisiert wird -- updateOrCreate() überschrieb dabei bisher
            // BEI JEDEM Lauf jedes Attribut JEDES bereits existierenden
            // Prüfungsausschusses mit dem CSV-Wert, auch wenn ein
            // Superadmin ihn zwischenzeitlich manuell korrigiert hatte
            // (z. B. eine veraltete Telefonnummer oder Adresse). Analog zu
            // CourseSeeder/ModuleSeeder/ExamRuleSetSeeder/
            // PraxisFormTemplateSeeder (Bug-Review Hoch) lässt firstOrCreate()
            // einen bereits existierenden Eintrag unberührt und legt nur
            // wirklich NEUE Prüfungsausschüsse mit den CSV-Werten an.
            Pruefungsausschuss::firstOrCreate(
                ['kommission' => $data['kommission'], 'standorttyp' => $data['standorttyp'] ?: null],
                [
                    'kontaktperson' => $data['kontaktperson'] ?: null,
                    'strasse' => $data['strasse'] ?: null,
                    'plz' => $data['plz'] ?: null,
                    'stadt' => $data['ort'] ?: null,
                    'telefon' => $data['telefon'] ?: null,
                    'fax' => $data['fax'] ?: null,
                    'email' => $data['email'] ?: null,
                    'website' => $data['website'] ?: null,
                    'sprechzeiten' => $data['sprechzeiten'] ?: null,
                    'hinweis' => $data['hinweis'] ?: null,
                    'quelle' => $data['quelle'] ?: null,
                    'sort_order' => $sortOrder++,
                ]
            );
        }

        fclose($handle);
    }
}
