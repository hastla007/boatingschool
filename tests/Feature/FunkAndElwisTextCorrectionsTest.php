<?php

namespace Tests\Feature;

use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use Tests\TestCase;

/**
 * Bug-Review (Texte): fachliche und sprachliche Korrekturen an Funk- und
 * ELWIS-Daten.
 *
 * - UBI-Aufnahme 4 / Praxisaufgabe "meeting": ein Bergfahrer am rechten Ufer
 *   begegnet Steuerbord an Steuerbord -- für "Backbord an Backbord" hält er
 *   sich am linken Ufer.
 * - FunkPraxis duzt den Lerner wie die übrige Oberfläche.
 * - Vokabeln: "Sturm" (Bft 8-9) = "gale", "Legerwall" statt "Leegerküste".
 * - ELWIS-Bild SBF Binnen 164 zeigt "Überholen an der Steuerbordseite des
 *   Vorausfahrenden", kein Nebelsignal.
 */
class FunkAndElwisTextCorrectionsTest extends TestCase
{
    public function test_ubi_aufnahme_text_about_meeting_is_consistent(): void
    {
        $text = FunkTraining::ubiTexts('aufnahme')[4]['text'];

        $this->assertStringContainsString('Ich bin Bergfahrer bei Rhein-Kilometer 554 und halte mich am linken Ufer. Begegnung Backbord an Backbord.', $text);
        $this->assertStringNotContainsString('rechten Ufer', $text);
    }

    public function test_praxis_meeting_task_is_consistent(): void
    {
        $task = collect(FunkPraxis::tasks('UBI'))->first(fn (array $task) => str_contains($task['situation_de'], 'Begegnung absprechen'));
        $this->assertNotNull($task);

        $this->assertStringContainsString('du hältst dich am linken Ufer', $task['situation_de']);
        $message = collect($task['fields'])->firstWhere('name', 'message')['solution'];
        $this->assertStringContainsString('ICH HALTE MICH AM LINKEN UFER, BEGEGNUNG BACKBORD AN BACKBORD', $message);
    }

    public function test_praxis_situations_address_the_learner_informally(): void
    {
        foreach (FunkPraxis::CATALOGS as $catalog) {
            foreach (FunkPraxis::tasks($catalog) as $task) {
                $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihnen)\b/u', $task['situation_de'], "{$task['key']}: förmliche Anrede");
            }
        }

        $first = FunkPraxis::tasks('SRC')[1]['situation_de'];
        $this->assertStringStartsWith('Du bist mit der ', $first);
    }

    public function test_vocabulary_uses_marine_forecast_terms_and_keeps_keys_unique(): void
    {
        $entries = collect(FunkTraining::vocabulary('SRC'))->flatten(1);
        $pairs = $entries->map(fn (array $e) => $e['de'].'|'.$e['en']);

        $this->assertContains('Sturm|gale', $pairs);
        $this->assertNotContains('Sturm|storm', $pairs);
        $this->assertContains('Sturmwarnung|gale warning', $pairs);
        $this->assertContains('Legerwall|lee shore', $pairs);
        $this->assertFalse($entries->contains(fn (array $e) => str_contains($e['de'], 'Leegerküste')));
        $this->assertSame($entries->count(), $entries->pluck('key')->unique()->count(), 'Vokabel-Schlüssel nicht eindeutig.');
    }

    public function test_elwis_description_of_binnen_164_names_the_overtaking_signal(): void
    {
        $handle = fopen(database_path('data/elwis_bilder.csv'), 'r');
        $header = fgetcsv($handle, null, ';');
        $descriptions = [];

        while (($row = fgetcsv($handle, null, ';')) !== false) {
            $row = array_combine($header, $row);
            $descriptions[$row['modul'].'-'.$row['fragen_nr']] = $row['elwis_beschreibung'];
        }

        fclose($handle);

        $this->assertSame('Überholen an der Steuerbordseite des Vorausfahrenden', $descriptions['SBF_BINNEN-164']);
        $this->assertStringNotContainsString('odere', implode("\n", $descriptions));
    }
}
