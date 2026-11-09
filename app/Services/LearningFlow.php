<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ablauf des Lernmodus (Smarttrainer und Varianten) -- gemeinsam für die
 * Web-Oberfläche (LearningController) und die Mobile-API. Eine Lernlogik,
 * ein Lernstand: Auswahl der nächsten Frage und Bewertung einer Antwort
 * liegen nur hier bzw. in SmarttrainerService/LearningService.
 */
class LearningFlow
{
    public const MODES = ['smarttrainer', 'new', 'wrong', 'topic', 'favorites'];

    public function __construct(
        private SmarttrainerService $smarttrainer,
        private LearningService $learning,
        private ExamEvaluationService $examEvaluation,
    ) {}

    /**
     * Nächste Frage oder null (alles erledigt bzw. Filter ohne Treffer).
     *
     * @param  list<string>|null  $questionIds  explizite Fragenliste („Fehler üben“)
     * @return array{question: ContentQuestion, reason: ?string}|null
     */
    public function nextQuestion(Tenant $tenant, User $user, CourseDefinition $course, string $mode, ?string $topic = null, ?string $moduleId = null, ?array $questionIds = null): ?array
    {
        if ($mode === 'favorites') {
            // Gültigkeit prüft die Auswahl unten (currentlyValid()) ohnehin.
            // Mit $questionIds (z. B. eine Sammlung oder „diese Frage üben“)
            // nur diese Favoriten -- dann auch aus dem Prüfungskontext.
            $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->when($questionIds === null, fn ($q) => $q->where('context', Favorite::CONTEXT_SMART_LEARNING))
                ->when($questionIds !== null, fn ($q) => $q->whereIn('question_id', $questionIds))
                ->whereIn('question_id', $this->courseQuestionIds($course))->pluck('question_id');

            // Bug-Review (Deaktivierte Favoriten, Endlosschleife) und X10
            // (Fragen einer laufenden Prüfung): nur gültige, nicht gesperrte
            // Favoriten anbieten.
            $blockedQuestionIds = $this->smarttrainer->runningExamQuestionIds($tenant, $user);
            $next = ContentQuestion::whereIn('id', $favoriteIds)
                ->currentlyValid()
                ->when($blockedQuestionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $blockedQuestionIds))
                ->inRandomOrder()
                ->first();

            return $next ? ['question' => $next, 'reason' => 'Favorit'] : null;
        }

        $learningCourse = $questionIds !== null ? $this->withExamPaperQuestions($course, $questionIds) : $course;

        return $this->smarttrainer->nextQuestion($tenant, $user, $learningCourse, $mode, $topic, $moduleId, $questionIds);
    }

    /**
     * Antwort prüfen und speichern. Wirft HttpException (403/404/409) wie
     * bisher der Web-Controller.
     *
     * @param  array{revision_id: string, answer_id?: ?string, answer_ids?: ?array, known?: ?bool, mode: string, started_at_ms?: ?int}  $input
     */
    public function submitAttempt(Tenant $tenant, User $user, CourseDefinition $course, array $input): Attempt
    {
        $responseTimeMs = isset($input['started_at_ms'])
            ? max(0, (int) round(microtime(true) * 1000) - $input['started_at_ms'])
            : null;

        $revision = ContentQuestionRevision::where('id', $input['revision_id'])
            ->whereIn('editorial_status', ['published', 'deprecated'])
            ->whereHas('question', fn ($q) => $q->currentlyValid())
            ->first();
        if (! $revision) {
            throw new HttpException(404, 'Diese Frage ist nicht (mehr) verfügbar.');
        }
        if (! $this->courseQuestionIds($course)->contains($revision->question_id)) {
            throw new HttpException(403, 'Frage gehört nicht zum berechtigten Kurs.');
        }

        // Fragen einer laufenden Prüfung sind im Lernmodus gesperrt
        // (abgelaufene Prüfungen werden dabei ausgewertet).
        $runningExamSessions = ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'running')
            ->whereHas('questions', fn ($q) => $q->where('question_id', $revision->question_id))
            ->get();
        foreach ($runningExamSessions as $runningExamSession) {
            // Review-Runde 13: während Nachfrist/Selbsteinschätzung der
            // Navigation bleibt die Prüfung offen (nicht hier auswerten).
            if (! $runningExamSession->stillOpen($this->examEvaluation->timeExpired($runningExamSession))) {
                $this->examEvaluation->evaluate($runningExamSession);

                continue;
            }

            throw new HttpException(409, 'Diese Frage ist Teil einer laufenden Prüfung und kann währenddessen nicht im Lernmodus bearbeitet werden.');
        }
        // … und auch bei einer anderen Schule (gleicher Kurs, gleiche Frage).
        if (ExamSession::blockedQuestionIdsFor($user->id)->contains($revision->question_id)) {
            throw new HttpException(409, 'Diese Frage ist Teil einer laufenden Prüfung und kann währenddessen nicht im Lernmodus bearbeitet werden.');
        }

        $selectedAnswer = null;
        $selectedAnswers = null;
        $selfAssessedKnown = null;

        if ($revision->question_type === 'multi_choice') {
            $selectedAnswers = ContentAnswer::where('revision_id', $revision->id)
                ->whereIn('id', $input['answer_ids'] ?? [])->get();
        } elseif ($revision->question_type === 'open_text') {
            $selfAssessedKnown = array_key_exists('known', $input) && $input['known'] !== null ? (bool) $input['known'] : null;
        } elseif ($input['answer_id'] ?? null) {
            $selectedAnswer = ContentAnswer::where('id', $input['answer_id'])->where('revision_id', $revision->id)->first();
            if (! $selectedAnswer) {
                throw new HttpException(404, 'Diese Antwort gehört nicht zur Frage.');
            }
        }

        return $this->learning->recordAttempt(
            $tenant, $user, $revision, $selectedAnswer, $input['mode'], $course, $responseTimeMs, $selectedAnswers, $selfAssessedKnown, $input['started_at_ms'] ?? null
        );
    }

    /**
     * Fragen des Kurses: Module UND Prüfungsbögen (Bug-Review SRC-UBI).
     *
     * @return Collection<int, string>
     */
    public function courseQuestionIds(CourseDefinition $course): Collection
    {
        return $course->contentQuestionIds();
    }

    /**
     * „Falsche Fragen erneut lernen“: explizit angefragte Bogenfragen
     * außerhalb der Module bekommen ein zusätzliches, nicht gespeichertes
     * Modul (siehe LearningController-Historie).
     *
     * @param  list<string>  $questionIds
     */
    private function withExamPaperQuestions(CourseDefinition $course, array $questionIds): CourseDefinition
    {
        $moduleQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        $extraIds = $course->examPaperQuestionIds()->intersect($questionIds)->diff($moduleQuestionIds);

        if ($extraIds->isEmpty()) {
            return $course;
        }

        $paperModule = new Module(['code' => 'EXAM_PAPERS', 'name' => 'Prüfungsbögen']);
        $paperModule->setRelation('questions', ContentQuestion::whereIn('id', $extraIds)
            ->with(['revisions' => fn ($q) => $q->where('editorial_status', 'published')->orderByDesc('revision_no')])
            ->get());

        $scopedCourse = clone $course;
        $scopedCourse->setRelation('modules', $course->modules->concat([$paperModule]));

        return $scopedCourse;
    }
}
