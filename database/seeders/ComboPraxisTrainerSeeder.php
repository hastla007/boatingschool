<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;

/**
 * Praxis-Prüfungstrainer für den Kombikurs "SBF See & Binnen" und die
 * Ergänzung Binnen -> See: wie Video- und Navigationsinhalte hängen
 * Praxisaufgaben am course_id des jeweiligen Kurses (siehe
 * ComboVideoCourseSeeder/ComboNavigationTaskSeeder). Die Aufgaben von
 * SBF See und SBF Binnen (Antriebsmaschine) werden daher unter der eigenen
 * Kurs-ID gespiegelt, inklusive Verweis auf bereits vorhandene Bild-Assets
 * (keine Dateiduplikate nötig). Inhaber des SBF Binnen (Antriebsmaschine)
 * fahren für SBF See praktisch nur die Pflichtmanöver (SpFV Anlage 4) --
 * die Ergänzung bekommt nur diese.
 *
 * Idempotent: fehlende Spiegelkarten werden ergänzt, vorhandene bleiben
 * unverändert (ElwisPraxisImageSeeder setzt deren Bilder danach über das
 * content_id-Präfix, z. B. "PRAXIS-BIN-0001-%").
 */
class ComboPraxisTrainerSeeder extends Seeder
{
    /**
     * Kurs-Code => Liste von [Quellkurs-Code, Suffix der content_id, nur
     * diese Kategorie (null = alle)], in Anzeigereihenfolge.
     *
     * Bug-Review (Kombikurs ohne Binnen-Praxiskarten): SBF-SEE-BIN bekam
     * bisher nur die 108 SBF-See-Karten, die 116 SBF-Binnen-Karten
     * (PRAXIS-BIN-*) fehlten, obwohl der Kombikurs auch auf die praktische
     * Binnen-Prüfung vorbereitet. Außerdem brach run() ab, sobald der
     * Zielkurs überhaupt Karten hatte -- bestehende Installationen hätten
     * die Binnen-Karten so nie erhalten.
     */
    private const TARGETS = [
        'SBF-SEE-BIN' => [
            ['SBF-SEE', '-SEE-BIN', null],
            ['SBF-BIN-MOTOR', '-SEE-BIN', null],
        ],
        'ADD-BIN-TO-SEE' => [
            ['SBF-SEE', '-BIN-SEE', 'Pflichtmanöver'],
        ],
    ];

    public function run(): void
    {
        foreach (self::TARGETS as $code => $sources) {
            $target = $this->globalCourse($code);

            if (! $target) {
                continue;
            }

            // Karten eines späteren Quellkurses folgen hinter denen des
            // vorherigen, statt sich über sort_order mit ihnen zu mischen.
            $sortOffset = 0;
            $coveredKarten = [];

            foreach ($sources as [$sourceCode, $suffix, $kategorie]) {
                $source = $this->globalCourse($sourceCode);

                if (! $source) {
                    continue;
                }

                // Karten, die eine frühere Quelle schon liefert (gleiche
                // Kategorie und Unterkategorie: Pflichtmanöver, Knoten,
                // sonstige Manöver, Sicherheit), nicht ein zweites Mal: im
                // Kombikurs stünden "Anlegen unter Maschine" usw. sonst
                // doppelt. Von SBF Binnen kommen damit die Binnen-eigenen
                // Kategorien und Binnen-eigene Karten wie "Sog und
                // Wellenschlag" oder "Großschifffahrt".
                $this->mirror($source, $target, $suffix, $kategorie, $sortOffset, $coveredKarten);
                foreach ($source->praxisTasks()->get(['kategorie', 'unterkategorie']) as $task) {
                    $coveredKarten[self::cardKey($task->kategorie, $task->unterkategorie)] = true;
                }
                $sortOffset += (int) $source->praxisTasks()->max('sort_order');
            }
        }
    }

    private function globalCourse(string $code): ?CourseDefinition
    {
        return CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();
    }

    /**
     * Gleiche Karte trotz anderer Wortstellung ("Wind und Strom" / "Strom
     * und Wind"): Vergleich über die sortierten Wörter.
     */
    private static function cardKey(string $kategorie, ?string $unterkategorie): string
    {
        $words = preg_split('/\s+/u', mb_strtolower((string) $unterkategorie), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return $kategorie.'|'.implode(' ', $words);
    }

    /** @param  array<string, true>  $skipKarten */
    private function mirror(CourseDefinition $source, CourseDefinition $target, string $suffix, ?string $kategorie, int $sortOffset, array $skipKarten): void
    {
        $existing = $target->praxisTasks()->pluck('content_id')->flip();

        $tasks = $source->praxisTasks()
            ->when($kategorie, fn ($q) => $q->where('kategorie', $kategorie))
            ->get()
            ->reject(fn (PraxisTask $task) => isset($skipKarten[self::cardKey($task->kategorie, $task->unterkategorie)]));

        foreach ($tasks as $sourceTask) {
            if ($existing->has($sourceTask->content_id.$suffix)) {
                continue;
            }

            PraxisTask::create([
                'course_id' => $target->id,
                'content_id' => $sourceTask->content_id.$suffix,
                'kategorie' => $sourceTask->kategorie,
                'unterkategorie' => $sourceTask->unterkategorie,
                'pruefungsbezug' => $sourceTask->pruefungsbezug,
                'aufgabentyp' => $sourceTask->aufgabentyp,
                'frage' => $sourceTask->frage,
                'antwort' => $sourceTask->antwort,
                'erklaerung' => $sourceTask->erklaerung,
                'media_asset_id' => $sourceTask->media_asset_id,
                'quelle' => $sourceTask->quelle,
                'quellen_url' => $sourceTask->quellen_url,
                'sort_order' => $sourceTask->sort_order + $sortOffset,
            ]);
        }
    }
}
