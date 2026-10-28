<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use Illuminate\Database\Seeder;

/**
 * Schaltet den UBI-Gesamtfragenkatalog Stand Oktober 2026 (ABVT Koblenz,
 * gültig ab 01.10.2026) auf bestehenden Installationen frei.
 *
 * Die UBI-Zeilen in zentrale_fragenbasis.xlsx tragen seit diesem Stand
 * source_version "2026-10". Auf einer frischen Installation legt
 * content:import sie direkt als veröffentlichte erste Revision an; auf
 * einer Installation mit dem bisherigen Katalog (08/2023) erzeugt
 * content:import für jede der 65 geänderten Fragen dagegen eine neue
 * Revision im Status "review", die Lernende erst nach Freigabe sehen
 * (siehe ImportContentQuestions). Für einen amtlich veröffentlichten
 * Katalog gibt es nichts redaktionell freizugeben -- dieser Seeder
 * veröffentlicht genau diese Revisionen, die bisherige Fassung bleibt als
 * "deprecated" erhalten (Attempts und Prüfungssitzungen verweisen
 * weiterhin auf die damals gezeigte Revision).
 *
 * Feedback-Texte und Bilder der alten Fassung werden bewusst NICHT
 * übernommen: unter 16 Nummern steht im neuen Katalog eine andere Frage
 * bzw. eine andere richtige Antwort, eine zur alten Fassung geschriebene
 * Erklärung wäre dort falsch.
 *
 * Idempotent: ohne wartende 2026-10-Revision tut ein erneuter Lauf nichts.
 */
class UbiCatalogUpdateSeeder extends Seeder
{
    public const SOURCE_VERSION = '2026-10';

    public function run(): void
    {
        $questions = ContentQuestion::where('content_role', 'ubi_official')->get();

        foreach ($questions as $question) {
            $latest = $question->revisions()->orderByDesc('revision_no')->first();

            if ($latest && $latest->editorial_status === 'review' && $latest->source_version === self::SOURCE_VERSION) {
                $latest->publish(inheritEditorialContent: false);
            }
        }
    }
}
