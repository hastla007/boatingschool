<?php

namespace App\Console\Commands;

use App\Models\ExamSession;
use App\Services\ExamEvaluationService;
use Illuminate\Console\Command;

/**
 * Bug-Review B32: eine Prüfungssession wird nur ausgewertet, wenn der
 * Lerner später noch einmal mit ihr interagiert (show()/answer()/finish()
 * in ExamController) -- schließt jemand nach dem Start einfach den Tab,
 * bleibt status="running" für immer stehen, egal ob die Prüfung ein
 * Zeitlimit hatte oder nicht. Läuft mandantenübergreifend über die
 * Owner-Verbindung, analog zu ExpireEntitlements/PrunePersonalData.
 */
class ExpireAbandonedExamSessions extends Command
{
    /**
     * Für Sessions ohne time_limit_seconds gibt es keine fachliche
     * Zeitgrenze, nach der automatisch ausgewertet werden dürfte -- diese
     * Kulanzfrist ist rein technisch (keine reale Prüfung dauert so lange)
     * und greift nur, wenn das Regelwerk selbst kein Zeitlimit vorsieht.
     */
    private const ABANDONED_GRACE_HOURS = 24;

    protected $signature = 'app:expire-abandoned-exam-sessions';

    protected $description = 'Wertet abgebrochene Prüfungssessions aus, die im Status "running" hängen geblieben sind.';

    public function handle(ExamEvaluationService $examEvaluation): int
    {
        config(['database.default' => 'pgsql_admin']);

        $sessions = ExamSession::where('status', 'running')->with('ruleSet')->get();
        $expired = 0;

        foreach ($sessions as $session) {
            $limit = $session->effectiveRuleSet()->time_limit_seconds;

            $abandoned = $limit
                ? $examEvaluation->timeExpired($session)
                : $session->started_at->lte(now()->subHours(self::ABANDONED_GRACE_HOURS));

            // Selbsteinschätzung der Navigation läuft noch (eigene Nachfrist).
            if (! $abandoned || $session->inNavigationAssessment()) {
                continue;
            }

            // Review-Runde 9: ohne eine einzige Antwort ist das kein Ergebnis
            // (0 % „nicht bestanden“ verdrängte sonst das letzte echte Ergebnis
            // auf der Bogen-Kachel und drückte die Bestehensquote der Schule).
            if (! $session->questions()->where(fn ($q) => $q->whereNotNull('answered_at')->orWhereNotNull('revealed_at'))->exists()) {
                $session->delete();
                $expired++;

                continue;
            }

            $examEvaluation->evaluate($session);
            $expired++;
        }

        $this->line("{$expired} abgebrochene Prüfungssession(s) ausgewertet bzw. (ohne Antwort) entfernt.");

        return self::SUCCESS;
    }
}
