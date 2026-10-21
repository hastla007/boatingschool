<?php

namespace App\Services;

use App\Models\Progress;
use Carbon\Carbon;

/**
 * V1-Mastery-Regel laut Umsetzungskonzept: "Eine konfigurierbare Regel
 * bewertet Anzahl und Reihenfolge richtiger Antworten." Diese Klasse bündelt
 * genau diese Regel an einer Stelle, damit sie später ersetzt werden kann,
 * ohne den Rest der Learning Loop anzufassen.
 */
class MasteryCalculator
{
    public function apply(Progress $progress, bool $correct, Carbon $now): void
    {
        $progress->attempt_count++;
        $progress->first_seen_at ??= $now;
        $progress->last_seen_at = $now;

        if ($correct) {
            $progress->correct_count++;
            $progress->current_streak++;
            $progress->last_correct_at = $now;
        } else {
            $progress->incorrect_count++;
            $progress->current_streak = 0;
        }

        $accuracy = $progress->attempt_count > 0 ? $progress->correct_count / $progress->attempt_count : 0;
        $streakFactor = min($progress->current_streak, 3) / 3;
        $progress->mastery_score = round(min(1, 0.4 * $accuracy + 0.6 * $streakFactor), 4);

        $progress->learning_state = $this->learningState($progress);
    }

    private function learningState(Progress $progress): string
    {
        if ($progress->attempt_count === 0) {
            return 'neu';
        }

        // "Gefestigt" erfordert 3 richtige Antworten in Folge -- ein
        // zwischenzeitlicher Fehler setzt den Streak zurück auf 0.
        if ($progress->current_streak >= 3) {
            return 'gefestigt';
        }

        if ($progress->current_streak === 0) {
            return 'unsicher';
        }

        return 'in_bearbeitung';
    }
}
