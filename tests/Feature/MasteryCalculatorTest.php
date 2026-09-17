<?php

namespace Tests\Feature;

use App\Models\Progress;
use App\Services\MasteryCalculator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * "Gefestigt" zählt 3 richtige Antworten insgesamt, nicht in Folge -- ein
 * zwischenzeitlicher Fehler reißt den Streak ab, aber nicht den Fortschritt
 * Richtung Mastery (siehe MasteryCalculator::learningState()).
 */
class MasteryCalculatorTest extends TestCase
{
    private function freshProgress(): Progress
    {
        $progress = new Progress();
        $progress->fill([
            'attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0,
            'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu',
        ]);

        return $progress;
    }

    public function test_three_correct_answers_in_a_row_are_mastered(): void
    {
        $progress = $this->freshProgress();
        $calculator = new MasteryCalculator();
        $now = Carbon::now();

        foreach ([true, true, true] as $correct) {
            $calculator->apply($progress, $correct, $now);
        }

        $this->assertSame(3, $progress->correct_count);
        $this->assertSame(3, $progress->current_streak);
        $this->assertSame('gefestigt', $progress->learning_state);
    }

    public function test_three_correct_answers_with_a_wrong_answer_in_between_are_also_mastered(): void
    {
        $progress = $this->freshProgress();
        $calculator = new MasteryCalculator();
        $now = Carbon::now();

        // Richtig, falsch (Streak reißt ab), richtig, richtig -> insgesamt
        // 3 richtige Antworten, aber kein Streak von 3 am Stück.
        foreach ([true, false, true, true] as $correct) {
            $calculator->apply($progress, $correct, $now);
        }

        $this->assertSame(3, $progress->correct_count);
        $this->assertSame(2, $progress->current_streak);
        $this->assertSame('gefestigt', $progress->learning_state);
    }

    public function test_two_correct_answers_are_not_yet_mastered(): void
    {
        $progress = $this->freshProgress();
        $calculator = new MasteryCalculator();
        $now = Carbon::now();

        foreach ([true, true] as $correct) {
            $calculator->apply($progress, $correct, $now);
        }

        $this->assertSame(2, $progress->correct_count);
        $this->assertNotSame('gefestigt', $progress->learning_state);
    }

    public function test_a_wrong_answer_resets_the_streak_but_not_the_correct_count(): void
    {
        $progress = $this->freshProgress();
        $calculator = new MasteryCalculator();
        $now = Carbon::now();

        $calculator->apply($progress, true, $now);
        $calculator->apply($progress, false, $now);

        $this->assertSame(1, $progress->correct_count);
        $this->assertSame(0, $progress->current_streak);
        $this->assertSame('unsicher', $progress->learning_state);
    }
}
