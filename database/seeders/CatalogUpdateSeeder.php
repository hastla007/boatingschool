<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use Illuminate\Database\Seeder;

/**
 * Schaltet Aktualisierungen aus amtlichen Katalogen auf bestehenden
 * Installationen frei.
 *
 * Eine in zentrale_fragenbasis.xlsx geänderte Zeile legt content:import
 * auf einer frischen Installation direkt als veröffentlichte erste
 * Revision an; auf einer bestehenden Installation erzeugt es dagegen eine
 * neue Revision im Status "review", die Lernende erst nach Freigabe sehen
 * (siehe ImportContentQuestions). Für den Text eines amtlich
 * veröffentlichten Katalogs gibt es nichts redaktionell freizugeben --
 * dieser Seeder veröffentlicht genau die Revisionen mit der unten
 * genannten source_version, die bisherige Fassung bleibt als "deprecated"
 * erhalten (Attempts und Prüfungssitzungen verweisen weiterhin auf die
 * damals gezeigte Revision).
 *
 * - UBI-Gesamtfragenkatalog Stand Oktober 2026 (ABVT Koblenz, gültig ab
 *   01.10.2026): 65 geänderte Fragen. Feedback-Texte und Bilder der alten
 *   Fassung werden NICHT übernommen -- unter 16 Nummern steht eine andere
 *   Frage bzw. eine andere richtige Antwort.
 * - SBF See 279 (ELWIS Fragenkatalog See, Stand August 2023): im PDF
 *   stehen Morsezeichen als Bild mitten in den Antworten (a-c SOS, d
 *   umgekehrt). Ohne sie waren die richtige Antwort A und die falsche D
 *   wortgleich; die Zeichen stehen jetzt als Text (mit geschützten
 *   Leerzeichen, damit sie nicht umbrechen) in den Antworten. Feedback
 *   bleibt gültig und wird übernommen.
 *
 * Idempotent: ohne wartende Revision tut ein erneuter Lauf nichts.
 */
class CatalogUpdateSeeder extends Seeder
{
    public const UBI_2026_10 = '2026-10';

    public const SEE_2023_08_ELWIS = '2023-08-elwis';

    private const UPDATES = [
        ['content_role' => 'ubi_official', 'source_version' => self::UBI_2026_10, 'inherit_editorial_content' => false],
        ['content_role' => 'see_specific', 'source_version' => self::SEE_2023_08_ELWIS, 'inherit_editorial_content' => true],
    ];

    public function run(): void
    {
        foreach (self::UPDATES as $update) {
            foreach (ContentQuestion::where('content_role', $update['content_role'])->get() as $question) {
                $latest = $question->revisions()->orderByDesc('revision_no')->first();

                if ($latest && $latest->editorial_status === 'review' && $latest->source_version === $update['source_version']) {
                    $latest->publish(inheritEditorialContent: $update['inherit_editorial_content']);
                }
            }
        }
    }
}
