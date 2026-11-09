<?php

namespace App\Services;

use App\Models\ContentAnswer;
use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Schreibende Schritte einer laufenden Prüfungssimulation, gemeinsam für das
 * Web (ExamController) und die Mobile-API: Antwort festschreiben,
 * Musterantwort offener Fragen abrufen, Navigationsaufgabe abgeben und
 * selbst einschätzen. Eine gegebene Antwort ist endgültig (wie auf Papier):
 * sie lässt sich nicht mehr ändern, die Auflösung gibt es erst nach der
 * Auswertung.
 *
 * Ergebnis ist jeweils einer der OUTCOME_*-Werte; ungültige Eingaben und
 * eine nicht mehr laufende Session enden als HttpException (422 bzw. 409).
 */
class ExamActions
{
    /** Gespeichert. */
    public const SAVED = 'saved';

    /** Die Frage bzw. Navigation war schon beantwortet: die erste Antwort zählt. */
    public const ALREADY_ANSWERED = 'already_answered';

    /** Zeit abgelaufen: die Prüfung wurde dabei ausgewertet. */
    public const EXPIRED = 'expired';

    /** Die Prüfung war bereits ausgewertet. */
    public const EVALUATED = 'evaluated';

    /** Navigation erst nach dem Fragebogen (solange die Zeit läuft). */
    public const QUESTIONS_OPEN = 'questions_open';

    public function __construct(private ExamEvaluationService $evaluation) {}

    /**
     * Antwort auf die Frage an $position festschreiben. $input:
     * answer_id (Einfachauswahl), answer_ids (Mehrfachauswahl) oder known
     * (offene Frage, nur nach reveal()).
     */
    public function answer(ExamSession $examSession, int $position, array $input): string
    {
        if ($examSession->status === 'evaluated') {
            return self::EVALUATED;
        }
        abort_if($examSession->status !== 'running', 409);

        // Sicherheitsreview M2: nach Ablauf der Zeit zählt keine Antwort mehr;
        // die Prüfung wird sofort ausgewertet.
        if ($this->evaluation->timeExpired($examSession)) {
            $this->evaluation->evaluate($examSession);

            return self::EXPIRED;
        }

        $sessionQuestion = $examSession->questions()->where('position', $position)
            ->whereNull('answered_at')->with('revision')->first();
        if (! $sessionQuestion) {
            return self::ALREADY_ANSWERED;
        }
        $type = $sessionQuestion->revision->question_type;

        // Bug-Review (Zwei gleichzeitige Prüfungsauswertungen stören sich):
        // die exam_session-Zeile wird gesperrt, bis eine nebenläufige
        // Auswertung committet hat, und der Status danach erneut geprüft --
        // evaluate() sperrt dieselbe Zeile, beide serialisieren sich.
        $outcome = self::SAVED;
        DB::transaction(function () use ($examSession, $sessionQuestion, $type, $input, &$outcome) {
            // DB::table() statt Eloquent: kein zweites Auswerten des Tenant-Scopes.
            DB::table('exam_session')->where('id', $examSession->id)->lockForUpdate()->first();
            $examSession->refresh();
            abort_if($examSession->status !== 'running', 409);
            // Review-Runde 9: die Zeit kann zwischen der Prüfung oben und der
            // Sperre abgelaufen sein -- dann zählt die Antwort nicht mehr.
            if ($this->evaluation->timeExpired($examSession)) {
                $outcome = self::EXPIRED;

                return;
            }

            // Doppelklick oder zweites Gerät: die zweite Anfrage überschreibt nichts.
            if ($examSession->questions()->where('position', $sessionQuestion->position)->whereNotNull('answered_at')->exists()) {
                $outcome = self::ALREADY_ANSWERED;

                return;
            }

            // Keine Sofortauflösung: correct wird erst bei der Auswertung befüllt.
            if ($type === 'multi_choice') {
                $given = array_values(array_unique((array) ($input['answer_ids'] ?? [])));
                abort_if($given === [], 422, 'Bitte mindestens eine Antwort auswählen.');
                $answerIds = ContentAnswer::where('revision_id', $sessionQuestion->revision_id)->whereIn('id', $given)->pluck('id');
                abort_if($answerIds->count() !== count($given), 422, 'Unbekannte Antwort.');

                $sessionQuestion->update([
                    'selected_answer_id' => null,
                    'selected_answer_ids' => $answerIds->values()->all(),
                    'answered_at' => now(),
                ]);
            } elseif ($type === 'open_text') {
                abort_if(! array_key_exists('known', $input) || $input['known'] === null, 422, 'Bitte Selbsteinschätzung angeben.');
                // Bug-Review Oberfläche U9: die Selbsteinschätzung zählt nur,
                // wenn die Musterantwort vorher tatsächlich abgerufen wurde.
                abort_unless($sessionQuestion->revealed_at, 422, 'Bitte zuerst die Musterantwort anzeigen.');

                $sessionQuestion->update([
                    'self_assessed_known' => (bool) $input['known'],
                    'answered_at' => now(),
                ]);
            } else {
                abort_if(empty($input['answer_id']), 422, 'Bitte eine Antwort auswählen.');
                $answer = ContentAnswer::where('id', $input['answer_id'])->where('revision_id', $sessionQuestion->revision_id)->first();
                abort_unless($answer, 422, 'Unbekannte Antwort.');

                $sessionQuestion->update([
                    'selected_answer_id' => $answer->id,
                    'answered_at' => now(),
                ]);
            }
        });

        if ($outcome === self::EXPIRED) {
            $this->evaluation->evaluate($examSession);
        }

        return $outcome;
    }

    /**
     * Musterantwort einer offenen Frage abrufen (Voraussetzung für die
     * Selbsteinschätzung). Liefert den Text oder null, wenn die Frage schon
     * beantwortet ist.
     *
     * @return array{outcome: string, answer_text: ?string}
     */
    public function reveal(ExamSession $examSession, int $position): array
    {
        abort_if($examSession->status !== 'running', 409);

        $sessionQuestion = $examSession->questions()->where('position', $position)
            ->whereNull('answered_at')->with('revision')->first();
        if (! $sessionQuestion) {
            return ['outcome' => self::ALREADY_ANSWERED, 'answer_text' => null];
        }
        abort_unless($sessionQuestion->revision->question_type === 'open_text', 422, 'Nur bei offenen Fragen gibt es eine Musterantwort.');

        if (! $sessionQuestion->revealed_at) {
            $sessionQuestion->update(['revealed_at' => now()]);
        }

        return ['outcome' => self::SAVED, 'answer_text' => $sessionQuestion->revision->correctAnswer()?->answer_text];
    }

    /**
     * Navigationsaufgabe abgeben: alle Teilantworten auf einmal, Schlüssel =
     * ID der Navigationsantwort der Session.
     *
     * @param  array<string, ?string>  $answers
     */
    public function submitNavigation(ExamSession $examSession, array $answers): string
    {
        if ($examSession->status === 'evaluated') {
            return self::EVALUATED;
        }
        abort_unless($examSession->status === 'running' && $examSession->hasNavigationPart(), 409);

        $outcome = self::SAVED;
        DB::transaction(function () use ($examSession, $answers, &$outcome) {
            DB::table('exam_session')->where('id', $examSession->id)->lockForUpdate()->first();
            $examSession->refresh();
            abort_if($examSession->status !== 'running', 409);
            if ($examSession->navigation_submitted_at) {
                $outcome = self::ALREADY_ANSWERED;

                return;
            }
            // Review-Runde 13: wie im Web erst der Fragebogen, dann die
            // Navigation -- sonst begann die Selbsteinschätzung (und mit ihr
            // die Auswertung) bei noch offenen Fragen. Nach Zeitablauf zählt
            // nur noch die Navigation.
            if (! $this->evaluation->timeExpired($examSession) && $examSession->questions()->whereNull('answered_at')->exists()) {
                $outcome = self::QUESTIONS_OPEN;

                return;
            }
            // Review-Runde 10: wer kurz nach Ablauf abgibt, verliert die ganze
            // Aufgabe nicht -- innerhalb der Nachfrist zählen die Antworten wie
            // zum Prüfungsende abgegeben (wie Papier, das eingesammelt wird).
            $deadline = $examSession->deadline();
            if ($deadline && now()->gt($deadline->copy()->addSeconds(ExamSession::NAVIGATION_SUBMIT_GRACE))) {
                $outcome = self::EXPIRED;

                return;
            }
            $answeredAt = $deadline && now()->gt($deadline) ? $deadline : now();
            foreach ($examSession->navigationAnswers as $answer) {
                $given = trim((string) ($answers[$answer->id] ?? ''));
                $answer->update(['answer' => $given === '' ? null : $given, 'answered_at' => $answeredAt]);
            }
            $examSession->update(['navigation_submitted_at' => now()]);
        });

        if ($outcome === self::EXPIRED) {
            $this->evaluation->evaluate($examSession);
        }

        return $outcome;
    }

    /**
     * Selbsteinschätzung der Navigations-Teilfragen, die sich nicht
     * automatisch prüfen lassen (bzw. „trotzdem richtig“ bei einer
     * automatisch als falsch erkannten Antwort); danach Auswertung.
     *
     * @param  array<string, bool|string|int|null>  $known  Schlüssel = ID der Navigationsantwort
     */
    public function assessNavigationAndFinish(ExamSession $examSession, array $known): string
    {
        if ($examSession->status === 'evaluated') {
            return self::EVALUATED;
        }
        abort_unless($examSession->status === 'running' && $examSession->navigation_submitted_at, 409);

        // Review-Runde 13: nach dem Fenster für die Selbsteinschätzung
        // (NAVIGATION_ASSESS_GRACE) zählt sie nicht mehr -- in Web und App
        // gleich; die Prüfung wird ohne sie ausgewertet.
        if (! $examSession->inNavigationAssessment()) {
            $this->evaluation->evaluate($examSession);

            return self::EXPIRED;
        }

        DB::transaction(function () use ($examSession, $known) {
            DB::table('exam_session')->where('id', $examSession->id)->lockForUpdate()->first();
            $examSession->refresh();
            abort_if($examSession->status !== 'running', 409);
            foreach ($examSession->navigationAnswers()->with('question')->get() as $answer) {
                $value = array_key_exists($answer->id, $known) && $known[$answer->id] !== null
                    ? filter_var($known[$answer->id], FILTER_VALIDATE_BOOLEAN) : null;
                if ($answer->answer === null && ! $answer->isChartDrawing()) {
                    $answer->update(['self_assessed_known' => false]); // leer = falsch
                } elseif (! $answer->isAutoCheckable()) {
                    $answer->update(['self_assessed_known' => $value]);
                } elseif ($answer->autoResult() === false) {
                    $answer->update(['self_assessed_known' => $value === true ? true : null]);
                }
            }
        });

        $this->evaluation->evaluate($examSession);

        return self::SAVED;
    }

    /** Prüfung abgeben und auswerten (wiederholbar). */
    public function finish(ExamSession $examSession): string
    {
        if ($examSession->status === 'evaluated') {
            return self::EVALUATED;
        }
        $this->evaluation->evaluate($examSession);

        return self::SAVED;
    }
}
