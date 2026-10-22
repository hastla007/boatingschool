<?php

namespace App\Services;

use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Prüfungs-Auswertung, geteilt zwischen dem regulären Abschluss
 * (ExamController::finish(), z. B. "Abgeben"-Button oder letzte Frage
 * beantwortet), dem serverseitigen Zeit-Timeout (ExamController::answer(),
 * siehe Sicherheitsreview M2) und dem Sweep für abgebrochene Sessions
 * (Bug-Review B32, siehe ExpireAbandonedExamSessions).
 */
class ExamEvaluationService
{
    public function timeExpired(ExamSession $examSession): bool
    {
        $limit = $examSession->ruleSet->time_limit_seconds;

        return $limit && $examSession->started_at->diffInSeconds(now()) > $limit;
    }

    /**
     * Eine Antwort zählt nur, wenn sie innerhalb der Zeitlimit-Frist ab
     * started_at gespeichert wurde -- das greift zusätzlich zur Sperre in
     * answer(), falls eine Antwort trotzdem noch knapp vor Ablauf
     * hereinkommt oder die Session kein laufendes time_limit_seconds hatte,
     * als sie gestartet wurde.
     */
    public function evaluate(ExamSession $examSession): void
    {
        if ($examSession->status === 'evaluated') {
            return;
        }

        DB::transaction(function () use ($examSession) {
            $examSession->refresh();

            if ($examSession->status === 'evaluated') {
                return;
            }

            $limit = $examSession->ruleSet->time_limit_seconds;
            $deadline = $limit ? $examSession->started_at->copy()->addSeconds($limit) : null;

            $questions = $examSession->questions()->with('revision.answers')->lockForUpdate()->get();
            $correctCount = 0;
            $scoredTotal = 0;

            foreach ($questions as $sq) {
                $withinTime = ! $deadline || ! $sq->answered_at || $sq->answered_at->lte($deadline);
                $type = $sq->revision->question_type;

                if ($type === 'multi_choice') {
                    $correctIds = $sq->revision->correctAnswers()->pluck('id')->sort()->values()->all();
                    $selectedIds = $withinTime ? collect($sq->selected_answer_ids ?? [])->sort()->values()->all() : [];
                    $isCorrect = $selectedIds !== [] && $selectedIds === $correctIds;
                    $sq->update(['correct' => $isCorrect]);
                    $correctCount += $isCorrect ? 1 : 0;
                    $scoredTotal++;
                } elseif ($type === 'open_text') {
                    // Selbsteinschätzung fließt nicht in die Bestehensgrenze ein,
                    // dient nur als Lernkarte (siehe self_assessed_known).
                    $sq->update(['correct' => $withinTime && (bool) $sq->self_assessed_known]);
                } else {
                    $correctAnswerId = $sq->revision->correctAnswer()?->id;
                    $selectedId = $withinTime ? $sq->selected_answer_id : null;
                    $isCorrect = $selectedId !== null && $selectedId === $correctAnswerId;
                    $sq->update(['correct' => $isCorrect]);
                    $correctCount += $isCorrect ? 1 : 0;
                    $scoredTotal++;
                }
            }

            $total = max($scoredTotal, 1);
            $score = round($correctCount / $total, 4);
            $minRatio = $examSession->ruleSet->passing_rule['min_correct_ratio'] ?? 0.75;

            $examSession->update([
                'status' => 'evaluated',
                'submitted_at' => now(),
                'score' => $score,
                'passed' => $score >= $minRatio,
            ]);
        });
    }
}
