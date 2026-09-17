<?php

namespace App\Services;

use App\Models\Progress;
use Carbon\Carbon;

/**
 * V1-Mastery-Regel laut Umsetzungskonzept: "Eine konfigurierbare Regel
 * bewertet Anzahl und Reihenfolge richtiger Antworten, Fehler, Zeit seit
 * der letzten Bearbeitung und den aktuellen Wiederholungstermin." Diese
 * Klasse bündelt genau diese Regel an einer Stelle, damit sie später ersetzt
 * werden kann, ohne den Rest der Learning Loop anzufassen.
 */
class MasteryCalculator
{
    /** Tage bis zur nächsten Wiederholung, gestaffelt nach aktueller Serie richtiger Antworten. */
    private const REVIEW_INTERVALS_DAYS = [1 => 1, 2 => 3, 3 => 7, 4 => 21];

    public function apply(Progress $progress, bool $correct, Carbon $now): void
    {
        $progress->attempt_count++;
        $progress->first_seen_at ??= $now;
        $progress->last_seen_at = $now;

        if ($correct) {
            $progress->correct_count++;
            $progress->current_streak++;
            $progress->last_correct_at = $now;
            $step = min($progress->current_streak, 4);
            $progress->next_review_at = $now->copy()->addDays(self::REVIEW_INTERVALS_DAYS[$step]);
        } else {
            $progress->incorrect_count++;
            $progress->current_streak = 0;
            $progress->next_review_at = $now->copy()->addHour();
        }

        $accuracy = $progress->attempt_count > 0 ? $progress->correct_count / $progress->attempt_count : 0;
        $streakFactor = min($progress->current_streak, 3) / 3;
        $progress->mastery_score = round(min(1, 0.4 * $accuracy + 0.6 * $streakFactor), 4);

        $progress->learning_state = $this->learningState($progress, $now);
    }

    private function learningState(Progress $progress, Carbon $now): string
    {
        if ($progress->attempt_count === 0) {
            return 'neu';
        }

        if ($progress->current_streak >= 3 && $progress->mastery_score >= 0.8) {
            return $progress->next_review_at && $progress->next_review_at->lte($now)
                ? 'wiederholung_faellig'
                : 'gefestigt';
        }

        if ($progress->current_streak === 0) {
            return 'unsicher';
        }

        return 'in_bearbeitung';
    }
}
