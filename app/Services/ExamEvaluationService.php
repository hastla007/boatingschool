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
            // Bug-Review (Zwei gleichzeitige Prüfungsauswertungen stören
            // sich): refresh() allein lockt nichts -- zwei fast gleichzeitig
            // ausgelöste Auswertungen derselben Session (z. B. "Abgeben" und
            // der serverseitige Zeit-Timeout aus answer(), siehe
            // ExamController) konnten beide status==='evaluated' als false
            // lesen, bevor eine von beiden committet hatte, und liefen dann
            // beide durch die Auswertung -- inklusive zweimaligem
            // $sessionQuestion->update() unten. Der Lock läuft bewusst über
            // den rohen Query-Builder (DB::table()), nicht über eine
            // zusätzliche Eloquent-Abfrage auf dem ExamSession-Model: eine
            // zweite Model-Abfrage würde den globalen Tenant-Scope
            // (BelongsToTenant) erneut auswerten und könnte je nach
            // Kontext (Konsole vs. Web-Request) von der bereits geladenen
            // $examSession-Instanz abweichen. DB::table() umgeht das, ohne
            // am anschließenden refresh() (unverändert gegenüber vorher)
            // etwas zu ändern.
            DB::table('exam_session')->where('id', $examSession->id)->lockForUpdate()->first();
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
                    // Bug-Review (Hoch): open_text floss hier bisher GAR NICHT
                    // in $scoredTotal/$correctCount ein -- die Bestehensquote
                    // bezog sich dadurch nur auf die automatisch auswertbaren
                    // Fragen eines Bogens, nicht auf alle. Für FKN (siehe
                    // ExamRuleSetSeeder/FknExamPaperSeeder: 15 Fragen/Bogen,
                    // amtlich 24 von 30 Punkten = 12 von 15 binär) hatten die
                    // amtlichen Prüfungsbögen aber nur 9-10 Nicht-open_text-
                    // Fragen -- die dokumentierte 80%-Grenze (12 von 15) wurde
                    // dadurch faktisch zu "8 von 9" bzw. "8 von 10" auf der
                    // verbleibenden Teilmenge, abhängig vom jeweiligen Bogen,
                    // statt wie beabsichtigt für ALLE 15 Fragen eines Bogens zu
                    // gelten. Die Selbsteinschätzung bleibt zwar subjektiv
                    // (kein automatisch geprüftes Freitext-Grading), ist aber
                    // dieselbe Grundlage, auf der diese Plattform open_text
                    // bereits überall sonst (Smarttrainer, Video-Quiz,
                    // Mastery-Berechnung) bewertet -- die Prüfungssimulation
                    // hier ist ohnehin nur ein Übungswerkzeug, nicht die echte,
                    // amtliche Prüfung vor dem Prüfungsausschuss (siehe
                    // Pruefungsausschuss-Model).
                    $isCorrect = $withinTime && (bool) $sq->self_assessed_known;
                    $sq->update(['correct' => $isCorrect]);
                    $correctCount += $isCorrect ? 1 : 0;
                    $scoredTotal++;
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
