<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Api\QuestionPresenter;
use App\Http\Controllers\Controller;
use App\Models\ContentQuestion;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionNavigationAnswer;
use App\Models\ExamSessionQuestion;
use App\Services\ExamActions;
use App\Services\ExamEvaluationService;
use App\Services\ExamSessionStarter;
use App\Support\ComboExamGroups;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prüfungssimulation in der App -- dieselbe Engine wie im Web
 * (ExamSessionStarter, ExamActions, ExamEvaluationService): Regelwerk-
 * Schnappschuss, Zeitlimit serverseitig, keine Auflösung vor der Abgabe,
 * eine gegebene Antwort ist festgeschrieben.
 */
class ExamController extends Controller
{
    public function __construct(
        private TenantContext $tenantContext,
        private CourseAccess $access,
        private ExamEvaluationService $evaluation,
        private ExamActions $actions,
    ) {}

    /** Startbare Prüfungen des Kurses: feste Bögen oder eine Zufallsprüfung nach Regelwerk. */
    public function index(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'exam');
        $papers = $this->access->limitItems($tenant, $user, $course, 'exam', $course->examPapers);

        $sessions = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->whereIn('status', ['running', 'evaluated'])->orderByDesc('started_at')->get();
        $lastEvaluated = $sessions->where('status', 'evaluated')->sortByDesc('submitted_at')->unique(fn ($s) => $s->paper_id ?? '')->keyBy(fn ($s) => $s->paper_id ?? '');
        $running = $sessions->where('status', 'running')
            ->filter(fn ($s) => $s->stillOpen($this->evaluation->timeExpired($s)))
            ->keyBy(fn ($s) => $s->paper_id ?? '');
        $counts = ExamSessionQuestion::whereIn('exam_session_id', $lastEvaluated->pluck('id'))
            ->selectRaw('exam_session_id, count(*) as question_count, sum(case when correct then 1 else 0 end) as correct_count')
            ->groupBy('exam_session_id')->get()->keyBy('exam_session_id');

        if ($papers->isNotEmpty()) {
            $totals = ExamPaperQuestion::whereIn('exam_paper_id', $papers->pluck('id'))
                ->whereIn('question_id', ContentQuestion::query()->currentlyValid()->select('id'))
                ->selectRaw('exam_paper_id, count(*) as total_count')->groupBy('exam_paper_id')
                ->pluck('total_count', 'exam_paper_id');
            $ruleSets = [];
            $options = $papers->map(function (ExamPaper $paper) use ($course, $totals, &$ruleSets, $lastEvaluated, $running, $counts) {
                $key = ComboExamGroups::sourceCourseFor($course->code, $paper->paper_number) ?? '';
                $ruleSet = $ruleSets[$key] ??= $course->examRuleSetForPaper($paper);
                $combo = ComboExamGroups::infoFor($course->code, $paper->paper_number);
                $number = $combo['displayNumber'] ?? $paper->paper_number;

                return $this->option('fixed_paper', $paper->id, trim(($combo['label'] ?? '').' Bogen '.$number), $combo['label'] ?? null, $number,
                    (int) $totals->get($paper->id, 0), $ruleSet, $lastEvaluated->get($paper->id), $running->get($paper->id), $counts);
            })->values();
        } else {
            $ruleSet = $course->activeExamRuleSet();
            $options = collect($ruleSet ? [$this->option('rule_set', $ruleSet->id, 'Prüfungssimulation', null, null,
                (int) $ruleSet->blueprints()->sum('question_count'), $ruleSet, $lastEvaluated->get(''), $running->get(''), $counts)] : []);
        }

        return response()->json([
            'data' => $options,
            'meta' => [
                'includes_navigation_task' => $course->navigationTasks()->exists(),
                // Review-Runde 13: ohne startbare Prüfung sagen, warum (z. B. Regelwerk noch Entwurf).
                'rule_set_status' => $options->isNotEmpty() ? 'verified' : ($course->examRuleSets()->exists() ? 'draft' : 'none'),
            ],
        ]);
    }

    /** Prüfung starten -- läuft für den Bogen schon eine, wird sie fortgesetzt (200 statt 201). */
    public function start(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate(['paper_id' => ['nullable', 'uuid']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'exam');
        $starter = app(ExamSessionStarter::class);

        if ($course->examPapers()->exists()) {
            if (empty($validated['paper_id'])) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['paper_id' => ['Bitte einen Prüfungsbogen wählen.']]);
            }
            $orderedPapers = $course->examPapers;
            if (! $this->access->allowsLimitedItem($tenant, $user, $course, 'exam', $orderedPapers, $validated['paper_id'])) {
                throw ApiProblem::notFound('Prüfungsbogen');
            }
            $paper = ExamPaper::where('id', $validated['paper_id'])->where('course_id', $course->id)->first();
            if (! $paper) {
                throw ApiProblem::notFound('Prüfungsbogen');
            }
            $ruleSet = $course->examRuleSetForPaper($paper);
            $this->requireVerified($ruleSet);
            $session = $starter->startPaper($tenant, $user, $course, $ruleSet, $paper);
        } else {
            if (! empty($validated['paper_id'])) {
                throw ApiProblem::notFound('Prüfungsbogen');
            }
            $ruleSet = $course->activeExamRuleSet();
            $this->requireVerified($ruleSet);
            $session = $starter->startRandom($tenant, $user, $course, $ruleSet);
        }

        return response()->json(['data' => $this->sessionPayload($session->fresh())], $session->wasRecentlyCreated ? 201 : 200);
    }

    /** Review-Runde 13: bisherige Prüfungsversuche des Kurses (neueste zuerst). */
    public function history(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'exam');
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $page = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->where('status', 'evaluated')->with('paper')->orderByDesc('submitted_at')->paginate($perPage);
        $counts = ExamSessionQuestion::whereIn('exam_session_id', $page->getCollection()->pluck('id'))
            ->selectRaw('exam_session_id, count(*) as question_count, sum(case when correct then 1 else 0 end) as correct_count')
            ->groupBy('exam_session_id')->get()->keyBy('exam_session_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (ExamSession $s) => [
                'exam_id' => $s->id,
                'paper_id' => $s->paper_id,
                'name' => $this->examName($s),
                'passed' => (bool) $s->passed,
                'score' => (float) $s->score,
                'correct_count' => (int) ($counts->get($s->id)->correct_count ?? 0),
                'question_count' => (int) ($counts->get($s->id)->question_count ?? 0),
                'navigation_points' => $s->navigation_points,
                'started_at' => Present::time($s->started_at),
                'evaluated_at' => Present::time($s->submitted_at),
            ])->values(),
            'meta' => ['pagination' => ['page' => $page->currentPage(), 'per_page' => $perPage, 'total' => $page->total(), 'last_page' => $page->lastPage()]],
        ]);
    }

    public function show(Request $request, string $examId): JsonResponse
    {
        $session = $this->ownSession($request, $examId);
        $this->expireIfOver($session);

        return response()->json(['data' => $this->sessionPayload($session->fresh())]);
    }

    /**
     * Antwort speichern. Eine gegebene Antwort ist festgeschrieben: dieselbe
     * Antwort noch einmal ist ein harmloser Wiederholungsversuch (200),
     * eine andere ergibt 409 EXAM_ANSWER_LOCKED.
     */
    public function answer(Request $request, string $examId, string $questionId): JsonResponse
    {
        $validated = $request->validate([
            'selected_answer_ids' => ['nullable', 'array', 'max:20'],
            'selected_answer_ids.*' => ['uuid', 'distinct'],
            'known' => ['nullable', 'boolean'],
        ]);
        $session = $this->ownSession($request, $examId);
        $this->requireRunning($session);
        $sq = $this->sessionQuestion($session, $questionId);

        if ($sq->answered_at) {
            return $this->repeatedAnswer($sq, $session, $validated);
        }

        $type = $sq->revision->question_type;
        $selected = array_values($validated['selected_answer_ids'] ?? []);
        if ($type === 'open_text') {
            $input = ['known' => $validated['known'] ?? null];
        } elseif ($type === 'multi_choice') {
            $input = ['answer_ids' => $selected];
        } else {
            if (count($selected) > 1) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['selected_answer_ids' => ['Bei dieser Frage ist genau eine Antwort richtig.']]);
            }
            $input = ['answer_id' => $selected[0] ?? null];
        }

        $outcome = $this->actions->answer($session, $sq->position, $input);
        $this->failOn($outcome);
        // Zusammengesetzter Schlüssel: neu laden statt fresh().
        $sq = $this->sessionQuestion($session, $sq->question_id);
        if ($outcome === ExamActions::ALREADY_ANSWERED) {
            return $this->repeatedAnswer($sq, $session, $validated);
        }

        return response()->json(['data' => $this->savedAnswer($sq, $session)]);
    }

    /** Mark or unmark a question for review in a running exam. */
    public function flag(Request $request, string $examId, string $questionId): JsonResponse
    {
        $validated = $request->validate(['flagged' => ['required', 'boolean']]);
        $session = $this->ownSession($request, $examId);
        $this->requireRunning($session);
        $sq = $this->sessionQuestion($session, $questionId);
        $flagged = (bool) $validated['flagged'];
        $sq->forceFill(['flagged' => $flagged])->save();

        return response()->json(['data' => [
            'question_id' => ctype_digit($questionId) ? $questionId : $sq->question_id,
            'flagged' => $flagged,
            'flagged_count' => $session->questions()->where('flagged', true)->count(),
        ]]);
    }

    /** Musterantwort einer offenen Frage abrufen (Voraussetzung für die Selbsteinschätzung). */
    public function reveal(Request $request, string $examId, string $questionId): JsonResponse
    {
        $session = $this->ownSession($request, $examId);
        $this->requireRunning($session);
        $sq = $this->sessionQuestion($session, $questionId);
        if ($sq->answered_at) {
            throw $this->locked();
        }
        if ($sq->revision->question_type !== 'open_text') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Keine offene Frage', 'Nur bei offenen Fragen gibt es eine Musterantwort.');
        }

        $revealed = $this->actions->reveal($session, $sq->position);
        if ($revealed['outcome'] === ExamActions::ALREADY_ANSWERED) {
            throw $this->locked();
        }

        return response()->json(['data' => ['question_id' => ctype_digit($questionId) ? $questionId : $sq->question_id, 'model_answer' => $revealed['answer_text']]]);
    }

    /** Navigationsaufgabe abgeben (alle Teilantworten auf einmal, einmalig). */
    public function submitNavigation(Request $request, string $examId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['present', 'array', 'max:50'],
            'answers.*' => ['nullable', 'string', 'max:500'],
        ]);
        $session = $this->ownSession($request, $examId);
        $this->requireRunning($session, allowNavigationGrace: true);
        if (! $session->hasNavigationPart()) {
            throw new ApiProblem(409, 'EXAM_NO_NAVIGATION_PART', 'Keine Navigationsaufgabe', 'Zu dieser Prüfung gehört keine Navigationsaufgabe.');
        }
        $unknown = array_diff(array_keys($validated['answers']), $session->navigationAnswers()->pluck('id')->all());
        if ($unknown !== []) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answers' => ['Unbekannte Teilfrage.']]);
        }

        $outcome = $this->actions->submitNavigation($session, $validated['answers']);
        if ($outcome === ExamActions::QUESTIONS_OPEN) {
            throw new ApiProblem(409, 'EXAM_QUESTIONS_OPEN', 'Fragen offen', 'Bitte zuerst alle Fragen beantworten – danach folgt die Navigationsaufgabe.');
        }
        if ($outcome === ExamActions::ALREADY_ANSWERED) {
            throw new ApiProblem(409, 'EXAM_NAVIGATION_ALREADY_SUBMITTED', 'Navigation bereits abgegeben', 'Die Navigationsaufgabe ist bereits abgegeben.');
        }
        $this->failOn($outcome);

        return response()->json(['data' => $this->sessionPayload($session->fresh())]);
    }

    /** Selbsteinschätzung der Navigation, danach Auswertung der ganzen Prüfung. */
    public function assessNavigation(Request $request, string $examId): JsonResponse
    {
        $validated = $request->validate([
            'known' => ['present', 'array', 'max:50'],
            'known.*' => ['nullable', 'boolean'],
        ]);
        $session = $this->ownSession($request, $examId);
        if ($session->status === 'evaluated') {
            throw $this->alreadySubmitted();
        }
        if (! $session->navigation_submitted_at) {
            throw new ApiProblem(409, 'EXAM_NAVIGATION_NOT_SUBMITTED', 'Navigation noch nicht abgegeben', 'Bitte zuerst die Navigationsaufgabe abgeben.');
        }
        $this->requireRunning($session, allowNavigationGrace: true);

        if ($this->actions->assessNavigationAndFinish($session, $validated['known']) === ExamActions::EXPIRED) {
            throw $this->expired();
        }

        return response()->json(['data' => $this->resultPayload($session->fresh())]);
    }

    /** Prüfung abgeben und auswerten; erneuter Aufruf liefert dasselbe Ergebnis. */
    public function submit(Request $request, string $examId): JsonResponse
    {
        $validated = $request->validate(['without_navigation' => ['nullable', 'boolean']]);
        $session = $this->ownSession($request, $examId);
        // Review-Runde 13: eine versehentliche Abgabe vor der Navigations-
        // aufgabe kostete sie ganz (SBF See: 7/9 Pflicht). Nur ausdrücklich
        // ohne Navigation abgeben -- oder nach Ablauf der Abgabefrist.
        if ($session->status === 'running' && $session->hasNavigationPart() && ! $session->navigation_submitted_at
            && empty($validated['without_navigation']) && $session->stillOpen($this->evaluation->timeExpired($session))) {
            throw new ApiProblem(409, 'EXAM_NAVIGATION_NOT_SUBMITTED', 'Navigation fehlt',
                'Die Navigationsaufgabe ist noch nicht abgegeben. Zum Abgeben ohne Navigation without_navigation: true senden.');
        }
        $this->actions->finish($session);

        return response()->json(['data' => $this->resultPayload($session->fresh())]);
    }

    public function result(Request $request, string $examId): JsonResponse
    {
        $session = $this->ownSession($request, $examId);
        $this->expireIfOver($session);
        $session->refresh();
        if ($session->status !== 'evaluated') {
            throw new ApiProblem(409, 'EXAM_NOT_SUBMITTED', 'Prüfung läuft noch', 'Das Ergebnis gibt es nach der Abgabe.');
        }

        return response()->json(['data' => $this->resultPayload($session)]);
    }

    // ---------------------------------------------------------------------

    private function option(string $kind, string $id, string $name, ?string $group, ?int $number, int $questionCount, ?ExamRuleSet $ruleSet, ?ExamSession $last, ?ExamSession $running, $counts): array
    {
        $ratio = (float) ($ruleSet?->passing_rule['min_correct_ratio'] ?? 0.75);
        $parts = $ruleSet?->passing_rule['parts'] ?? [];
        $lastCounts = $last ? $counts->get($last->id) : null;

        return [
            'id' => $id,
            'kind' => $kind,
            'name' => $name,
            'group' => $group,
            'number' => $number,
            'question_count' => $questionCount,
            'duration_seconds' => $ruleSet?->time_limit_seconds,
            'pass_rule' => $ruleSet ? [
                'description' => $ruleSet->passingSummary(max(1, $questionCount)),
                'minimum_correct' => $parts === [] ? (int) ceil($ratio * $questionCount - 1e-9) : null,
                'minimum_score' => $ratio,
                'parts' => array_map(fn ($p) => ['label' => $p['label'], 'min_correct' => (int) $p['min_correct'], 'of' => (int) $p['of']], $parts),
                'navigation' => $ruleSet->navigationRule(),
                'note' => $ruleSet->note(),
            ] : null,
            'startable' => (bool) $ruleSet?->isVerified() && $questionCount > 0,
            'running_exam_id' => $running?->id,
            'last_result' => $last ? [
                'exam_id' => $last->id,
                'passed' => (bool) $last->passed,
                'score' => (float) $last->score,
                'correct_count' => (int) ($lastCounts->correct_count ?? 0),
                'question_count' => (int) ($lastCounts->question_count ?? 0),
                'navigation_points' => $last->navigation_points,
                'evaluated_at' => Present::time($last->submitted_at),
            ] : null,
        ];
    }

    private function requireVerified(?ExamRuleSet $ruleSet): void
    {
        if (! $ruleSet || ! $ruleSet->isVerified()) {
            throw new ApiProblem(409, 'EXAM_NOT_AVAILABLE', 'Prüfung nicht verfügbar', 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');
        }
    }

    /** Eigene Session in dieser Schule; Kurszugang muss weiter bestehen (wie im Web, Bug-Review B11). */
    private function ownSession(Request $request, string $examId): ExamSession
    {
        $tenant = $this->tenantContext->tenant();
        $session = ExamSession::where('id', $examId)->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->first();
        if (! $session) {
            throw ApiProblem::notFound('Prüfung');
        }
        $this->access->require($tenant, $request->user(), $session->course_id, 'exam');

        return $session;
    }

    /** Abgelaufene Zeit (ohne Navigations-Nachfrist): wie im Web sofort auswerten. */
    private function expireIfOver(ExamSession $session): void
    {
        if ($session->status === 'running' && ! $session->stillOpen($this->evaluation->timeExpired($session))) {
            $this->evaluation->evaluate($session);
        }
    }

    private function requireRunning(ExamSession $session, bool $allowNavigationGrace = false): void
    {
        if ($session->status === 'evaluated') {
            throw $this->alreadySubmitted();
        }
        if ($session->status !== 'running') {
            throw new ApiProblem(409, 'CONFLICT', 'Prüfung nicht aktiv');
        }
        if (! $this->evaluation->timeExpired($session)) {
            return;
        }
        // Zeit um. Läuft noch die Nachfrist bzw. Selbsteinschätzung der
        // Navigation, bleibt die Prüfung dafür offen (Review-Runde 13: eine zu
        // späte Antwort auf eine Frage wertete sonst alles aus und die
        // Navigation ging verloren).
        if ($session->stillOpen(true)) {
            if ($allowNavigationGrace) {
                return;
            }
            throw $this->expired();
        }
        $this->evaluation->evaluate($session);
        throw $this->expired();
    }

    private function failOn(string $outcome): void
    {
        if ($outcome === ExamActions::EVALUATED) {
            throw $this->alreadySubmitted();
        }
        if ($outcome === ExamActions::EXPIRED) {
            throw $this->expired();
        }
    }

    private function sessionQuestion(ExamSession $session, string $questionId): ExamSessionQuestion
    {
        $sq = $session->questions()->where('question_id', $questionId)->with('revision.answers')->first();
        if (! $sq && ctype_digit($questionId)) {
            $sq = $session->questions()->where('position', (int) $questionId)->with('revision.answers')->first();
        }
        if (! $sq) {
            throw ApiProblem::notFound('Frage');
        }

        return $sq;
    }

    private function repeatedAnswer(ExamSessionQuestion $sq, ExamSession $session, array $validated): JsonResponse
    {
        $same = $sq->revision->question_type === 'open_text'
            ? array_key_exists('known', $validated) && $validated['known'] !== null && (bool) $validated['known'] === (bool) $sq->self_assessed_known
            : collect($validated['selected_answer_ids'] ?? [])->sort()->values()->all() === collect($this->selectedIds($sq))->sort()->values()->all();
        if (! $same) {
            throw $this->locked();
        }

        return response()->json(['data' => $this->savedAnswer($sq, $session)]);
    }

    /** @return list<string> */
    private function selectedIds(ExamSessionQuestion $sq): array
    {
        if ($sq->selected_answer_ids) {
            return array_values($sq->selected_answer_ids);
        }

        return $sq->selected_answer_id ? [$sq->selected_answer_id] : [];
    }

    private function savedAnswer(ExamSessionQuestion $sq, ExamSession $session): array
    {
        return [
            'saved' => true,
            'question_id' => $sq->question_id,
            'selected_answer_ids' => $this->selectedIds($sq),
            'known' => $sq->revision->question_type === 'open_text' ? $sq->self_assessed_known : null,
            'saved_at' => Present::time($sq->answered_at),
            'flagged' => (bool) $sq->flagged,
            'answered_count' => $session->questions()->whereNotNull('answered_at')->count(),
            'question_count' => $session->questions()->count(),
        ];
    }

    private function phase(ExamSession $session, int $open): string
    {
        if ($session->status === 'evaluated') {
            return 'evaluated';
        }
        if ($session->hasNavigationPart()) {
            if ($session->navigation_submitted_at) {
                return 'navigation_assessment';
            }
            // Fragebogen fertig -- oder Zeit um: dann zählt nur noch die
            // Abgabe der Navigation (Nachfrist).
            if ($open === 0 || $this->evaluation->timeExpired($session)) {
                return 'navigation';
            }
        }

        return $open === 0 ? 'ready_to_submit' : 'questions';
    }

    private function sessionPayload(ExamSession $session): array
    {
        $questions = $session->questions()->with('revision.answers', 'revision.media')->get();
        $open = $questions->whereNull('answered_at')->count();
        $deadline = $session->deadline();
        $phase = $this->phase($session, $open);

        return [
            'exam_id' => $session->id,
            'course_id' => $session->course_id,
            'paper_id' => $session->paper_id,
            'name' => $this->examName($session),
            'pass_rule' => $session->effectiveRuleSet()->passingSummary(max(1, $questions->count())),
            'status' => $session->status,
            'phase' => $phase,
            'started_at' => Present::time($session->started_at),
            'expires_at' => Present::time($deadline),
            'server_time' => Present::time(now()),
            'remaining_seconds' => $deadline ? max(0, (int) now()->diffInSeconds($deadline, false)) : null,
            // Fristen der Navigationsaufgabe (Abgabe nach Zeitablauf, Selbsteinschätzung).
            'navigation_submit_until' => $deadline && $session->hasNavigationPart() ? Present::time($deadline->copy()->addSeconds(ExamSession::NAVIGATION_SUBMIT_GRACE)) : null,
            'navigation_assessment_until' => $deadline && $session->hasNavigationPart() ? Present::time($deadline->copy()->addSeconds(ExamSession::NAVIGATION_ASSESS_GRACE)) : null,
            'question_count' => $questions->count(),
            'answered_count' => $questions->count() - $open,
            'flagged_count' => $questions->where('flagged', true)->count(),
            'questions' => $questions->map(fn (ExamSessionQuestion $sq) => QuestionPresenter::question($sq->revision, null, $session->user_id) + [
                'position' => $sq->position,
                'flagged' => (bool) $sq->flagged,
                'answered' => $sq->answered_at !== null,
                'revealed' => $sq->revealed_at !== null,
                // Musterantwort einer offenen Frage nur nach dem Abruf (reveal) und solange offen.
                'model_answer' => $sq->revision->question_type === 'open_text' && $sq->revealed_at && ! $sq->answered_at
                    ? $sq->revision->correctAnswer()?->answer_text : null,
            ])->values(),
            'answers' => $questions->whereNotNull('answered_at')->map(fn (ExamSessionQuestion $sq) => [
                'question_id' => $sq->question_id,
                'selected_answer_ids' => $this->selectedIds($sq),
                'known' => $sq->revision->question_type === 'open_text' ? $sq->self_assessed_known : null,
                'answered_at' => Present::time($sq->answered_at),
            ])->values(),
            'navigation' => $this->navigationPayload($session, $phase === 'navigation_assessment' || $phase === 'evaluated'),
        ];
    }

    private function navigationPayload(ExamSession $session, bool $withSolutions): ?array
    {
        if (! $session->hasNavigationPart()) {
            return null;
        }
        $task = $session->navigationTaskAsStarted();
        $rule = $session->effectiveRuleSet()->navigationRule();

        return [
            'task_id' => $task?->id,
            'title' => $task ? 'Navigationsaufgabe '.$task->task_number : 'Navigationsaufgabe',
            'scenario' => $task?->scenario_text,
            // Wie im Web: der Hinweis erst bei der Selbsteinschätzung.
            'hint' => $withSolutions ? $task?->hint : null,
            'chart' => $task?->chart_image_path ? Present::image(Present::publicFile($task->chart_image_path), $task->chart_caption ?: 'Kartenausschnitt') : null,
            'rule' => $rule,
            'submitted_at' => Present::time($session->navigation_submitted_at),
            'points' => $session->status === 'evaluated' ? $session->navigation_points : null,
            'passed' => $session->status === 'evaluated' && $rule ? ($session->navigation_points ?? 0) >= $rule['min_points'] : null,
            'parts' => $session->navigationAnswers()->with('question')->get()->map(fn (ExamSessionNavigationAnswer $a) => [
                'id' => $a->id,
                'number' => $a->number(),
                'prompt' => $a->questionText(),
                'chart_drawing' => $a->isChartDrawing(),
                'answer' => $a->answer,
                'auto_checkable' => $a->isAutoCheckable(),
                'auto_result' => $withSolutions ? $a->autoResult() : null,
                'solution' => $withSolutions ? $a->modelAnswer() : null,
                // Selbsteinschätzung nötig: nicht automatisch prüfbar, oder automatisch als falsch erkannt („trotzdem gleichwertig“).
                'needs_assessment' => $withSolutions && $session->status === 'running'
                    && ($a->answer !== null || $a->isChartDrawing()) && (! $a->isAutoCheckable() || $a->autoResult() === false),
                'self_assessed_known' => $a->self_assessed_known,
                'correct' => $session->status === 'evaluated' ? (bool) $a->correct : null,
            ])->values(),
        ];
    }

    private function resultPayload(ExamSession $session): array
    {
        $questions = $session->questions()->with('revision.answers', 'revision.media')->get();
        $ruleSet = $session->effectiveRuleSet();
        $openText = $questions->filter(fn ($q) => $q->revision?->question_type === 'open_text');

        return [
            'exam_id' => $session->id,
            'course_id' => $session->course_id,
            'paper_id' => $session->paper_id,
            'name' => $this->examName($session),
            'status' => 'evaluated',
            'passed' => (bool) $session->passed,
            'score' => (float) $session->score,
            'correct_count' => $questions->where('correct', true)->count(),
            'question_count' => $questions->count(),
            'unanswered_count' => $questions->whereNull('answered_at')->count(),
            'started_at' => Present::time($session->started_at),
            'evaluated_at' => Present::time($session->submitted_at),
            'rule_set_version' => $ruleSet->version !== null ? (string) $ruleSet->version : null,
            'pass_rule' => $ruleSet->passingSummary(max(1, $questions->count())),
            'parts' => $ruleSet->partResults($questions),
            'self_assessed' => ['question_count' => $openText->count(), 'correct_count' => $openText->where('correct', true)->count()],
            'by_topic' => $questions->groupBy(fn ($q) => ($q->revision?->smartmodus_kategorie ?: $q->revision?->topic) ?? '—')
                ->map(fn ($rows, $topic) => ['topic' => (string) $topic, 'correct_count' => $rows->where('correct', true)->count(), 'question_count' => $rows->count()])
                ->values(),
            'questions' => $questions->map(fn (ExamSessionQuestion $sq) => QuestionPresenter::question($sq->revision, null, $session->user_id) + [
                'position' => $sq->position,
                'selected_answer_ids' => $this->selectedIds($sq),
                'known' => $sq->revision->question_type === 'open_text' ? $sq->self_assessed_known : null,
                'answered' => $sq->answered_at !== null,
                'correct' => (bool) $sq->correct,
                'solution' => QuestionPresenter::solution($sq->revision, (bool) $sq->correct),
            ])->values(),
            'navigation' => $this->navigationPayload($session, true),
        ];
    }

    private function examName(ExamSession $session): string
    {
        $paper = $session->paper;
        if (! $paper) {
            return 'Prüfungssimulation';
        }
        $combo = ComboExamGroups::infoFor((string) $session->course?->code, $paper->paper_number);

        return trim(($combo['label'] ?? '').' Bogen '.($combo['displayNumber'] ?? $paper->paper_number));
    }

    private function locked(): ApiProblem
    {
        return new ApiProblem(409, 'EXAM_ANSWER_LOCKED', 'Antwort festgeschrieben', 'Diese Frage ist bereits beantwortet; die Antwort lässt sich nicht mehr ändern.');
    }

    private function alreadySubmitted(): ApiProblem
    {
        return new ApiProblem(409, 'EXAM_ALREADY_SUBMITTED', 'Prüfung bereits abgegeben', 'Die Prüfung ist bereits ausgewertet.');
    }

    private function expired(): ApiProblem
    {
        return new ApiProblem(410, 'EXAM_EXPIRED', 'Prüfungszeit abgelaufen', 'Die Prüfungszeit ist abgelaufen; die Prüfung wurde ausgewertet.');
    }
}
