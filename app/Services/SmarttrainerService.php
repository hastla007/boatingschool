<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Transparente, deterministische Priorisierung: "1. noch nie gesehen,
 * 2. falsch/mehrfach falsch, 3. niedrige Mastery, 4. schwaches Thema."
 * Keine ML-Abhängigkeit. apply() ist eine reine Funktion über bereits
 * geladene Kandidaten, damit sie ohne DB deterministisch getestet werden
 * kann.
 */
class SmarttrainerService
{
    public function __construct(private ExamEvaluationService $examEvaluation) {}

    public function nextQuestion(Tenant $tenant, User $user, CourseDefinition $course, string $mode = 'smarttrainer', ?string $topic = null, ?string $moduleId = null, ?array $questionIds = null): ?array
    {
        $course->loadMissing(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $modules = $moduleId ? $course->modules->where('id', $moduleId) : $course->modules;
        // Bug-Review B12: eine im Superadmin deaktivierte Frage
        // (content_question.active) wurde hier weiter als Kandidat
        // ausgespielt -- active=true schließt sie von der Auswahl aus.
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questions = ContentQuestion::filterCurrentlyValid($modules->flatMap(fn ($module) => $module->questions)->unique('id'));

        // Bug-Review (Smarttrainer blockiert während laufender Prüfung):
        // storeAttempt() (LearningController) lehnt eine Antwort auf eine
        // Frage ab, die Teil einer noch laufenden (nicht abgelaufenen)
        // Prüfungssession dieses Nutzers ist (Sicherheitsreview M2) -- hier
        // bot nextQuestion() genau so eine Frage bisher trotzdem aktiv als
        // nächste Lernfrage an, und schlug dann erst beim anschließenden
        // Abschicken der Antwort mit 409 fehl, statt direkt eine andere
        // Frage auszuspielen.
        $blockedQuestionIds = $this->runningExamQuestionIds($tenant, $user);
        if ($blockedQuestionIds->isNotEmpty()) {
            $questions = $questions->reject(fn ($question) => $blockedQuestionIds->contains($question->id));
        }

        if ($questionIds !== null) {
            $questions = $questions->whereIn('id', $questionIds);
        }

        if ($questions->isEmpty()) {
            return null;
        }

        $progressByQuestion = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('question_id', $questions->pluck('id'))
            ->get()
            ->keyBy('question_id');

        $topicAccuracy = $this->topicAccuracy($progressByQuestion, $questions);

        $candidates = match ($mode) {
            'new' => $questions->filter(fn ($q) => ! $progressByQuestion->has($q->id)),
            'wrong' => $questions->filter(fn ($q) => $this->wasLastWrong($progressByQuestion->get($q->id))),
            // Bug-Review (Kachel "Sonstiges" zeigt "keine Fragen"): die
            // Themen-Kachel auf der Kursübersicht (CourseProgressService::
            // moduleKategorieBreakdown()) gruppiert Fragen ohne
            // smartmodus_kategorie/topic unter dem Label "Sonstiges" --
            // questionTopic() liefert für genau diese Fragen aber null statt
            // "Sonstiges". Ein Klick auf diese Kachel (?topic=Sonstiges)
            // verglich bisher null === 'Sonstiges', was nie zutrifft, und
            // filterte dadurch ALLE Fragen heraus, obwohl die Kachel eine
            // Anzahl > 0 zeigte (z. B. bei FKN/SKN, deren Fragen kein Thema
            // haben). Der Vergleich wendet hier denselben Fallback an wie
            // die Kachel-Gruppierung, statt questionTopic() selbst global zu
            // ändern (das würde auch score()'s "Schwaches Thema"-Gewichtung
            // für themenlose Fragen beeinflussen, was außerhalb dieses Bugs
            // liegt).
            'topic' => $topic ? $questions->filter(fn ($q) => ($this->questionTopic($q) ?: 'Sonstiges') === $topic) : $questions,
            default => $questions,
        };

        if ($candidates->isEmpty()) {
            return null;
        }

        $ranked = $candidates
            ->map(function (ContentQuestion $question) use ($progressByQuestion, $topicAccuracy) {
                [$score, $reason] = $this->score($question, $progressByQuestion->get($question->id), $topicAccuracy);

                return ['question' => $question, 'score' => $score, 'reason' => $reason];
            })
            ->sortByDesc('score')
            ->values();

        $top = $ranked->first();

        return [
            'question' => $top['question'],
            'reason' => $top['reason'],
            'score' => $top['score'],
        ];
    }

    /** @return array{0: float, 1: string} */
    private function score(ContentQuestion $question, ?Progress $progress, Collection $topicAccuracy): array
    {
        if (! $progress || $progress->attempt_count === 0) {
            return [1000, 'Noch nie gesehen'];
        }

        if ($this->wasLastWrong($progress)) {
            return [800 + min($progress->incorrect_count, 10) * 10, 'Zuletzt falsch beantwortet'];
        }

        $topic = $this->questionTopic($question);
        $weak = $topic && ($topicAccuracy->get($topic) ?? 1) < 0.6;
        $base = (1 - $progress->mastery_score) * 300;

        if ($weak) {
            return [$base + 150, "Schwaches Thema: {$topic}"];
        }

        return [$base, 'Gefestigt, niedrige Priorität'];
    }

    private function wasLastWrong(?Progress $progress): bool
    {
        return $progress && $progress->attempt_count > 0 && $progress->current_streak === 0;
    }

    /**
     * Bug-Review X10 (Favoriten/Video-Quiz zeigen Fragen aus laufender
     * Prüfung): public, damit LearningController (Favoriten-Modus) und
     * VideoQuizController dieselbe Sperre wie nextQuestion() oben
     * anwenden können, statt eine blockierte Frage erst auszuspielen und
     * erst beim Abschicken mit 409 abzulehnen.
     *
     * @return Collection<int, string>
     */
    public function runningExamQuestionIds(Tenant $tenant, User $user): Collection
    {
        return ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'running')
            ->with('questions')
            ->get()
            ->reject(fn (ExamSession $session) => $this->examEvaluation->timeExpired($session))
            ->flatMap(fn (ExamSession $session) => $session->questions->pluck('question_id'));
    }

    private function questionTopic(ContentQuestion $question): ?string
    {
        $revision = $question->relationLoaded('revisions')
            ? $question->revisions->first()
            : $question->publishedRevision();

        return $revision?->smartmodus_kategorie ?: $revision?->topic;
    }

    private function topicAccuracy(Collection $progressByQuestion, Collection $questions): Collection
    {
        return $questions
            ->groupBy(fn ($q) => $this->questionTopic($q) ?? '—')
            ->map(function ($group) use ($progressByQuestion) {
                $rows = $group->map(fn ($q) => $progressByQuestion->get($q->id))->filter();
                $attempts = $rows->sum('attempt_count');
                $correct = $rows->sum('correct_count');

                return $attempts > 0 ? $correct / $attempts : 1;
            });
    }
}
