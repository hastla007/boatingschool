<?php

namespace Tests\Unit;

use App\Support\FunkPraxis;
use Tests\TestCase;

/**
 * Review der Funk-Bewertung: Zahlwörter bei Position und Uhrzeit, Umlaute
 * in Schiffsnamen, kein „Kanal“ ohne Nummer im Binnenfunk.
 */
class FunkPraxisReviewTest extends TestCase
{
    private function ok(array $evaluation, string $field, string $label): bool
    {
        foreach ($evaluation['fields'][$field] as $result) {
            if (str_contains($result['label'], $label)) {
                return $result['ok'];
            }
        }
        $this->fail("Prüfpunkt {$label} fehlt");
    }

    public function test_position_and_time_spoken_digit_by_digit_are_recognised(): void
    {
        $task = FunkPraxis::tasks('SRC')[3];
        $message = 'ALL STATIONS ALL STATIONS ALL STATIONS THIS IS PASSAT PASSAT PASSAT DELTA CHARLIE SEVEN TWO NINE EIGHT MMSI TWO ONE ONE FOUR ONE SEVEN SEVEN ONE ONE POSITION FIVE FOUR DEGREES TWO ZERO MINUTES NORTH ZERO ZERO SEVEN DEGREES THREE ZERO MINUTES EAST CANCEL MY DISTRESS ALERT OF ONE SIX ONE EIGHT UTC OUT';
        $evaluation = FunkPraxis::evaluate($task, ['message' => $message]);

        $this->assertTrue($this->ok($evaluation, 'message', 'Position'));
        $this->assertTrue($this->ok($evaluation, 'message', 'Uhrzeit'));
        // Falsche Ziffern bleiben falsch.
        $wrong = FunkPraxis::evaluate($task, ['message' => str_replace('ONE SIX ONE EIGHT', 'ONE SIX TWO EIGHT', $message)]);
        $this->assertFalse($this->ok($wrong, 'message', 'Uhrzeit'));
    }

    public function test_umlauts_in_vessel_names_match_either_spelling(): void
    {
        $task = FunkPraxis::tasks('SRC')[13];
        foreach (['STRANDLÄUFER', 'Strandlaeufer'] as $name) {
            $evaluation = FunkPraxis::evaluate($task, ['message' => "MAYDAY MAYDAY MAYDAY THIS IS {$name} {$name} {$name}"]);
            $this->assertTrue($this->ok($evaluation, 'message', 'Name dreimal'), $name);
        }
    }

    public function test_a_channel_without_the_number_of_the_nautical_information_is_wrong(): void
    {
        $this->assertFalse($this->ok(FunkPraxis::evaluate(FunkPraxis::tasks('UBI')[10], ['radio' => 'Kanal 16, 25 W']), 'radio', 'Kanal der Nautischen Information'));
        $this->assertFalse($this->ok(FunkPraxis::evaluate(FunkPraxis::tasks('UBI')[8], ['radio' => 'Kanal 16']), 'radio', 'Kanal der Nautischen Information'));
        $this->assertTrue($this->ok(FunkPraxis::evaluate(FunkPraxis::tasks('UBI')[10], ['radio' => 'Kanal 18, 25 W']), 'radio', 'Kanal der Nautischen Information'));
    }

    public function test_a_position_spoken_digit_by_digit_without_units_is_recognised(): void
    {
        // Review-Runde 5: Die Spracherkennung schreibt „five four“ oft als
        // „5 4“; ohne „degrees“ folgen die Zahlen direkt aufeinander.
        $pattern = '/'.(new \ReflectionMethod(FunkPraxis::class, 'posPattern'))->invoke(null, [54, 10, 'N', 7, 52, 'E']).'/u';
        foreach ([
            'MY POSITION 5 4 DEGREES 1 0 MINUTES NORTH 0 0 7 DEGREES 5 2 MINUTES EAST',
            'POSITION FIVE FOUR ONE ZERO NORTH ZERO ZERO SEVEN FIVE TWO EAST',
            'POSITION 54 DEGREES 1 0 MINUTES NORTH 7 DEGREES 52 MINUTES EAST',
            'MY POSITION N54 10 E007 52',
            "MY POSITION 54°10'N 007°52'E",
        ] as $spoken) {
            $this->assertMatchesRegularExpression($pattern, FunkPraxis::normalize($spoken), $spoken);
        }
        foreach ([
            'POSITION FIVE FOUR DEGREES ONE ZERO MINUTES NORTH ZERO ZERO SEVEN TWO DEGREES FIVE TWO MINUTES EAST',
            'POSITION 54 10 NORTH 72 52 EAST',
            'POSITION FIVE FOUR ONE SEVEN NORTH ZERO ZERO SEVEN FIVE TWO EAST',
            'POSITION FIVE FOUR SEVENTEEN NORTH SEVEN FIFTY TWO EAST',
        ] as $wrong) {
            $this->assertDoesNotMatchRegularExpression($pattern, FunkPraxis::normalize($wrong), $wrong);
        }
    }

    public function test_the_nautical_information_channels_follow_the_inland_radio_handbook(): void
    {
        // Review: Kanal 24 gehört zur Nautischen Information, Kanal 21 nicht;
        // „25 W“ ist die Sendeleistung, nicht Kanal 25.
        $nif = fn (string $radio) => $this->ok(FunkPraxis::evaluate(FunkPraxis::tasks('UBI')[8], ['radio' => $radio]), 'radio', 'Kanal der Nautischen Information');
        foreach ([18, 22, 24, 28, 73, 79, 81, 84, 86] as $channel) {
            $this->assertTrue($nif("Kanal {$channel}"), "Kanal {$channel}");
        }
        $this->assertFalse($nif('Kanal 21'));
        $this->assertFalse($nif('Kanal 16, 25 W'));
        $this->assertTrue($nif('Kanal 25, 25 W'));
    }

    public function test_inland_mayday_relay_and_pan_pan_are_addressed_to_the_revierzentrale(): void
    {
        $task = fn (string $kind) => collect(FunkPraxis::tasks('UBI'))->firstWhere('kind', $kind);
        $message = fn (array $t) => collect($t['fields'])->firstWhere('name', 'message')['solution'];

        $relay = $task('relay');
        $this->assertStringContainsString('REVIERZENTRALE', $message($relay));
        $this->assertTrue($this->ok(FunkPraxis::evaluate($relay, ['message' => $message($relay)]), 'message', 'An die Revierzentrale'));
        // MAYDAY RELAY ungerichtet „an alle“ statt an die Revierzentrale: falsch.
        $toAll = str_replace('REVIERZENTRALE REVIERZENTRALE REVIERZENTRALE', 'AN ALLE FUNKSTELLEN', $message($relay));
        $this->assertFalse($this->ok(FunkPraxis::evaluate($relay, ['message' => $toAll]), 'message', 'An die Revierzentrale'));

        // PAN PAN: an die Revierzentrale, ausnahmsweise an alle Funkstellen.
        $urgency = $task('urgency');
        $this->assertStringContainsString('REVIERZENTRALE', $message($urgency));
        $this->assertTrue($this->ok(FunkPraxis::evaluate($urgency, ['message' => str_replace('REVIERZENTRALE REVIERZENTRALE REVIERZENTRALE', 'AN ALLE FUNKSTELLEN', $message($urgency))]), 'message', 'An die Revierzentrale'));
        $this->assertFalse($this->ok(FunkPraxis::evaluate($urgency, ['message' => str_replace('REVIERZENTRALE REVIERZENTRALE REVIERZENTRALE', '', $message($urgency))]), 'message', 'An die Revierzentrale'));
    }
}
