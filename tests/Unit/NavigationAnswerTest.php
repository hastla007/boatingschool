<?php

namespace Tests\Unit;

use App\Support\NavigationAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NavigationAnswerTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function answers(): array
    {
        return [
            'Kurs exakt' => ['rwK = 079°', 'rwK = 079°', true],
            'Kurs ohne Einheit, 2° daneben' => ['rwK = 079°', '81', true],
            'Kurs 3° daneben' => ['rwK = 079°', '076°', false],
            'Kurs über 360' => ['rwK = 359°', '001°', true],
            'Position' => ["54° 03,5' N 008° 06,9' E", "54°03,7'N 008°06,6'E", true],
            'Position ohne Zeichen' => ["54° 03,5' N 008° 06,9' E", '54 03,5 N 8 06,9 E', true],
            'Position 1 Minute daneben' => ["54° 03,5' N 008° 06,9' E", "54° 04,5' N 008° 06,9' E", false],
            'Peilung und Distanz' => ['BV = 103° - 0,8 sm', '104 0,9', true],
            'Distanz daneben' => ['BV = 103° - 0,8 sm', '103° 1,2 sm', false],
            'Uhrzeit' => ['15:12 Uhr', '15:14', true],
            'Uhrzeit daneben' => ['15:12 Uhr', '15.20 Uhr', false],
            'Fahrt' => ['FüG = 6 kn', '6,2 kn', true],
            'Fahrt daneben' => ['FüG = 6 kn', '5,5', false],
            'Leere Antwort' => ['rwK = 079°', '', false],
        ];
    }

    #[DataProvider('answers')]
    public function test_measurements_are_compared_with_tolerance(string $model, string $given, bool $correct): void
    {
        $result = NavigationAnswer::evaluate($model, $given);

        $this->assertTrue($result['auto']);
        $this->assertSame($correct, $result['correct']);
    }

    public function test_descriptive_solutions_need_self_assessment(): void
    {
        foreach (['Siehe Karte', 'Farbe: gelb Kennung: gelbes Blitzfeuer mit 4 Sekunden Wiederkehr', 'Wrack, Kartentiefe 53,6 m'] as $model) {
            $result = NavigationAnswer::evaluate($model, 'irgendwas');
            $this->assertFalse($result['auto'], $model);
            $this->assertNull($result['correct']);
        }
    }

    public function test_every_real_model_answer_matches_itself(): void
    {
        $handle = fopen(__DIR__.'/../../database/data/sbf_see_navigationsaufgaben.csv', 'r');
        fgetcsv($handle, null, ';');
        $checked = 0;
        while (($row = fgetcsv($handle, null, ';')) !== false) {
            $answer = $row[5];
            $result = NavigationAnswer::evaluate($answer, $answer);
            if ($result['auto']) {
                $this->assertTrue($result['correct'], $answer);
                $checked++;
            }
        }
        fclose($handle);
        $this->assertGreaterThan(50, $checked);
    }
}
