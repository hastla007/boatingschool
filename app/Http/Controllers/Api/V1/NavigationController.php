<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\NavigationProgress;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use App\Support\NavigationAnswer;
use App\Support\NavigationChart;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Navigationsaufgaben-Trainer (Übung mit Musterlösung auf Anforderung,
 * eigener Antwort mit Toleranzprüfung und gemerktem Stand -- wie im Web).
 * Eine Aufgabe, die gerade Teil einer laufenden Prüfung ist, bleibt
 * gesperrt (409 NAVIGATION_TASK_IN_EXAM).
 */
class NavigationController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $course = $this->access->require($tenant, $request->user(), $courseId, 'navigation');
        // Probezugang: nur die ersten N Aufgaben.
        $tasks = $this->access->limitItems($tenant, $request->user(), $course, 'navigation', $course->navigationTasks()->orderBy('id')->with('questions')->get());
        $progress = $this->progress($request, $tasks);
        // Review-Runde 13: offene Prüfungen einmal laden statt je Aufgabe.
        $lockedTaskIds = ExamSession::lockedNavigationTaskIdsFor($request->user()->id)->flip();

        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        return response()->json([
            'data' => $tasks->slice(($page - 1) * $perPage, $perPage)->values()->map(fn (NavigationTask $t) => $this->summary($t, $progress, $lockedTaskIds->has($t->id))),
            'meta' => ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $tasks->count(), 'last_page' => max(1, (int) ceil($tasks->count() / $perPage))]],
        ]);
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $showSolution = $request->boolean('show_solution');
        $task = $this->accessibleTask($request, $taskId);
        $this->abortIfInRunningExam($request, $task);
        $progress = $this->progress($request, collect([$task]));

        return response()->json(['data' => $this->summary($task, $progress, false) + [
            'scenario' => $task->scenario_text,
            'hint' => $task->hint,
            'chart' => $task->chart_image_path ? Present::image(NavigationChart::url($task->chart_image_path, $request->user(), $this->tenantContext->tenant()), $task->chart_caption ?: 'Kartenausschnitt') : null,
            'parts' => $task->questions->map(fn (NavigationQuestion $q) => [
                'id' => $q->id,
                'number' => (int) $q->question_number,
                'prompt' => $q->question_text,
                'auto_checkable' => (bool) NavigationAnswer::evaluate($q->answer_text, '')['auto'],
                'chart_drawing' => (bool) preg_match('/^\s*siehe\s+karte/i', (string) $q->answer_text),
                'placeholder' => NavigationAnswer::placeholder($q->answer_text),
                'own_answer' => $progress->get($q->id)?->answer,
                'correct' => $progress->get($q->id)?->correct,
                'self_assessment' => $progress->get($q->id)?->self_assessment,
                'solution' => $showSolution ? $q->answer_text : null,
            ])->values(),
        ]]);
    }

    /**
     * Eigene Antwort prüfen und merken (`answer`) oder sich nach dem
     * Aufdecken selbst bewerten (`self_assessment`: correct|incorrect).
     */
    public function answer(Request $request, string $taskId, string $partId): JsonResponse
    {
        $validated = $request->validate([
            'answer' => ['nullable', 'string', 'max:500', 'required_without:self_assessment'],
            'self_assessment' => ['nullable', 'in:correct,incorrect', 'required_without:answer'],
        ]);
        [$question] = $this->accessiblePart($request, $taskId, $partId);

        if (! empty($validated['self_assessment'])) {
            [$row, $evaluation] = $this->store($request, $question, null, $validated['self_assessment'] === 'correct' ? 'correct' : 'wrong');
        } else {
            $given = trim((string) $validated['answer']);
            if ($given === '') {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answer' => ['Bitte zuerst eine Antwort eingeben.']]);
            }
            [$row, $evaluation] = $this->store($request, $question, $given, null);
        }

        return response()->json(['data' => [
            'part_id' => $question->id,
            'own_answer' => $row->answer,
            'auto_checked' => $evaluation !== null && (bool) $evaluation['auto'],
            // null: nicht automatisch prüfbar -- Musterlösung ansehen und selbst bewerten.
            'correct' => $evaluation !== null && ! $evaluation['auto'] ? null : $row->correct,
            'self_assessment' => $row->self_assessment,
            'solution' => $question->answer_text,
        ]]);
    }

    /**
     * Antwort im Format der bisherigen App: Antwort und/oder dreistufige
     * Selbstbewertung (correct|partly|wrong) in einem Aufruf. Fehlt `answer`
     * (oder ist leer/null), bleibt die gespeicherte Antwort stehen.
     */
    public function answerCompatible(Request $request, string $taskId, string $partId): JsonResponse
    {
        $validated = $request->validate([
            'answer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'self_assessment' => ['sometimes', 'nullable', 'in:correct,partly,wrong'],
        ]);
        [$question] = $this->accessiblePart($request, $taskId, $partId);
        $answer = trim((string) ($validated['answer'] ?? ''));
        $answer = $answer === '' ? null : $answer;
        $assessment = $validated['self_assessment'] ?? null;
        if ($answer === null && $assessment === null) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answer' => ['Bitte eine Antwort oder Selbstbewertung senden.']]);
        }

        [$row, $evaluation] = $this->store($request, $question, $answer, $assessment);
        $autoChecked = $evaluation !== null && (bool) $evaluation['auto'];

        return response()->json(['data' => [
            'part_id' => $question->id,
            'solution' => $question->answer_text,
            'my_answer' => $row->answer,
            'self_assessment' => $row->self_assessment,
            'done' => $row->self_assessment !== null,
            'auto_check' => $autoChecked,
            'check' => $autoChecked ? [
                'status' => $evaluation['correct'] ? 'within' : 'deviates',
                'message' => $evaluation['correct'] ? 'Deine Werte liegen im Toleranzbereich.' : 'Mindestens ein Wert weicht ab oder fehlt.',
            ] : null,
        ]]);
    }

    /**
     * Stand einer Teilfrage speichern. $answer: neue Antwort (null lässt sie
     * stehen); $assessment: Selbstbewertung (null lässt sie stehen, eine
     * neue Antwort ohne Bewertung setzt sie zurück).
     *
     * @return array{0: NavigationProgress, 1: ?array}
     */
    private function store(Request $request, NavigationQuestion $question, ?string $answer, ?string $assessment): array
    {
        $key = ['tenant_id' => $this->tenantContext->id(), 'user_id' => $request->user()->id, 'navigation_question_id' => $question->id];
        $values = ['updated_at' => now()];
        $evaluation = null;

        if ($answer !== null) {
            $evaluation = NavigationAnswer::evaluate($question->answer_text, $answer);
            $values += ['answer' => $answer, 'correct' => $evaluation['correct'], 'self_assessment' => null];
        }
        if ($assessment !== null) {
            $values['self_assessment'] = $assessment;
            $values['correct'] = $assessment === 'correct';
        }

        return [NavigationProgress::updateOrCreate($key, $values), $evaluation];
    }

    /** @return array{0: NavigationQuestion, 1: NavigationTask} */
    private function accessiblePart(Request $request, string $taskId, string $partId): array
    {
        $task = $this->accessibleTask($request, $taskId);
        $this->abortIfInRunningExam($request, $task);
        $question = $task->questions->firstWhere('id', $partId);
        if (! $question) {
            throw ApiProblem::notFound('Teilfrage');
        }

        return [$question, $task];
    }

    private function accessibleTask(Request $request, string $taskId): NavigationTask
    {
        $task = NavigationTask::with('questions')->find($taskId);
        if (! $task) {
            throw ApiProblem::notFound('Navigationsaufgabe');
        }
        $tenant = $this->tenantContext->tenant();
        $course = $this->access->require($tenant, $request->user(), $task->course_id, 'navigation');
        if (! $this->access->allowsLimitedItem($tenant, $request->user(), $course, 'navigation', $course->navigationTasks()->orderBy('id')->get(['id']), (string) $task->id)) {
            throw CourseAccess::itemLocked();
        }

        return $task;
    }

    private function abortIfInRunningExam(Request $request, NavigationTask $task): void
    {
        if ($exam = ExamSession::runningWithNavigationTask($this->tenantContext->id(), $request->user()->id, $task->id)) {
            throw new ApiProblem(409, 'NAVIGATION_TASK_IN_EXAM', 'Aufgabe gerade in der Prüfung',
                'Diese Navigationsaufgabe ist gerade Teil deiner laufenden Prüfungssimulation. Die Übung ist danach wieder verfügbar.',
                extra: ['exam_id' => $exam->id]);
        }
    }

    /** @return Collection<string, NavigationProgress> */
    private function progress(Request $request, Collection $tasks): Collection
    {
        $questionIds = NavigationQuestion::whereIn('navigation_task_id', $tasks->pluck('id'))->pluck('id');

        return NavigationProgress::where('tenant_id', $this->tenantContext->id())->where('user_id', $request->user()->id)
            ->whereIn('navigation_question_id', $questionIds)->get()->keyBy('navigation_question_id');
    }

    private function summary(NavigationTask $task, Collection $progress, bool $locked): array
    {
        $rows = $task->questions->map(fn ($q) => $progress->get($q->id))->filter();
        $status = $rows->isEmpty() ? 'new'
            : ($rows->count() === $task->questions->count() && $rows->every(fn ($p) => $p->correct === true) ? 'done' : 'started');

        return [
            'id' => $task->id,
            'number' => (int) $task->task_number,
            'title' => 'Navigationsaufgabe '.$task->task_number,
            'description' => null,
            'part_count' => $task->questions->count(),
            'status' => $status,
            'correct_count' => $rows->where('correct', true)->count(),
            'has_chart' => (bool) $task->chart_image_path,
            'locked_by_exam' => $locked,
        ];
    }
}
