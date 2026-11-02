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
}
