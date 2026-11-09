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
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId);
        $tasks = $course->navigationTasks()->with('questions')->get();
        $progress = $this->progress($request, $tasks);

        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        return response()->json([
            'data' => $tasks->slice(($page - 1) * $perPage, $perPage)->values()->map(fn (NavigationTask $t) => $this->summary($request, $t, $progress)),
            'meta' => ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $tasks->count(), 'last_page' => max(1, (int) ceil($tasks->count() / $perPage))]],
        ]);
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $showSolution = $request->boolean('show_solution');
        $task = $this->accessibleTask($request, $taskId);
        $this->abortIfInRunningExam($request, $task);
        $progress = $this->progress($request, collect([$task]));

        return response()->json(['data' => $this->summary($request, $task, $progress) + [
            'scenario' => $task->scenario_text,
            'hint' => $task->hint,
            'chart' => $task->chart_image_path ? Present::image(Present::publicFile($task->chart_image_path), $task->chart_caption ?: 'Kartenausschnitt') : null,
            'parts' => $task->questions->map(fn (NavigationQuestion $q) => [
                'id' => $q->id,
                'number' => (int) $q->question_number,
                'prompt' => $q->question_text,
                'auto_checkable' => (bool) NavigationAnswer::evaluate($q->answer_text, '')['auto'],
                'chart_drawing' => (bool) preg_match('/^\s*siehe\s+karte/i', (string) $q->answer_text),
                'placeholder' => NavigationAnswer::placeholder($q->answer_text),
                'own_answer' => $progress->get($q->id)?->answer,
                'correct' => $progress->get($q->id)?->correct,
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
        $task = $this->accessibleTask($request, $taskId);
        $this->abortIfInRunningExam($request, $task);
        $question = $task->questions->firstWhere('id', $partId);
        if (! $question) {
            throw ApiProblem::notFound('Teilfrage');
        }

        $key = ['tenant_id' => $this->tenantContext->id(), 'user_id' => $request->user()->id, 'navigation_question_id' => $question->id];
        $existing = NavigationProgress::where($key)->first();
        $evaluation = null;
        if (! empty($validated['self_assessment'])) {
            $values = ['answer' => $existing?->answer, 'correct' => $validated['self_assessment'] === 'correct'];
        } else {
            $given = trim((string) $validated['answer']);
            if ($given === '') {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answer' => ['Bitte zuerst eine Antwort eingeben.']]);
            }
            $evaluation = NavigationAnswer::evaluate($question->answer_text, $given);
            $values = ['answer' => $given, 'correct' => $evaluation['correct']];
        }
        $row = NavigationProgress::updateOrCreate($key, $values + ['updated_at' => now()]);

        return response()->json(['data' => [
            'part_id' => $question->id,
            'own_answer' => $row->answer,
            'auto_checked' => $evaluation !== null && (bool) $evaluation['auto'],
            // null: nicht automatisch prüfbar -- Musterlösung ansehen und selbst bewerten.
            'correct' => $evaluation !== null && ! $evaluation['auto'] ? null : $row->correct,
            'solution' => $question->answer_text,
        ]]);
    }

    private function accessibleTask(Request $request, string $taskId): NavigationTask
    {
        $task = NavigationTask::with('questions')->find($taskId);
        if (! $task) {
            throw ApiProblem::notFound('Navigationsaufgabe');
        }
        $this->access->require($this->tenantContext->tenant(), $request->user(), $task->course_id);

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

    private function summary(Request $request, NavigationTask $task, Collection $progress): array
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
            'locked_by_exam' => ExamSession::runningWithNavigationTask($this->tenantContext->id(), $request->user()->id, $task->id) !== null,
        ];
    }
}
