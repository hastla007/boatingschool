<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\NavigationProgress;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use App\Services\EntitlementService;
use App\Services\ExamEvaluationService;
use App\Support\NavigationAnswer;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Navigationsaufgaben-Trainer: Übungsmaterial mit sofort einsehbarer
 * Musterlösung, bewusst getrennt von der strengen Prüfungssimulation
 * (siehe ExamController), da hier "Sofortauflösung" gerade gewünscht ist.
 *
 * Eigene Antworten werden mit Toleranz geprüft, wenn die Musterlösung nur
 * aus Messwerten besteht (NavigationAnswer); sonst bewertet sich der
 * Lernende nach dem Aufdecken selbst. Der Stand wird je Teilfrage gemerkt.
 */
class NavigationTaskController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $tasks = $course->navigationTasks;
        abort_if($tasks->isEmpty(), 404, 'Für diesen Kurs sind noch keine Navigationsaufgaben hinterlegt.');

        return $this->show($request, $tenantContext, $entitlements, $course, $tasks->first());
    }

    /** Review-Runde 10: Lösungen der Aufgabe, die gerade in einer laufenden Prüfung dran ist, nicht zeigen. */
    private function runningExamWithTask(Request $request, TenantContext $tenantContext, NavigationTask $task): ?ExamSession
    {
        return ExamSession::where('tenant_id', $tenantContext->id())->where('user_id', $request->user()->id)
            ->where('status', 'running')->where('navigation_task_id', $task->id)->get()
            ->first(fn ($s) => ! app(ExamEvaluationService::class)->timeExpired($s));
    }

    /** Review-Runde 10: Lösungen der Aufgabe, die gerade in einer laufenden Prüfung dran ist, nicht zeigen. */
    private function abortIfInRunningExam(Request $request, TenantContext $tenantContext, NavigationTask $task): void
    {
        abort_if($this->runningExamWithTask($request, $tenantContext, $task) !== null, 409, 'Diese Navigationsaufgabe ist gerade Teil deiner laufenden Prüfungssimulation. Die Übung ist danach wieder verfügbar.');
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, NavigationTask $task): View|RedirectResponse
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $tasks = $course->navigationTasks->load('questions');
        abort_unless($tasks->contains('id', $task->id), 404, 'Diese Aufgabe gehört nicht zu diesem Kurs.');

        $task = $tasks->firstWhere('id', $task->id);
        if ($running = $this->runningExamWithTask($request, $tenantContext, $task)) {
            return redirect()->route('exam.show', $running)
                ->with('status', 'Diese Navigationsaufgabe ist gerade Teil deiner laufenden Prüfung – die Übung ist danach wieder verfügbar.');
        }
        $progress = $this->progress($request, $tasks);

        return view('exam.navigation', [
            'course' => $course,
            'tasks' => $tasks,
            'task' => $task,
            'progress' => $progress,
            'taskStatus' => $tasks->mapWithKeys(fn (NavigationTask $t) => [$t->id => $this->taskStatus($t, $progress)]),
            'autoCheck' => $task->questions->mapWithKeys(fn (NavigationQuestion $q) => [$q->id => NavigationAnswer::evaluate($q->answer_text, '')['auto']]),
        ]);
    }

    /**
     * Eigene Antwort speichern (mit automatischer Prüfung, wo möglich),
     * oder die Selbstbewertung nach dem Aufdecken der Musterlösung.
     */
    public function answer(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, NavigationTask $task, NavigationQuestion $question): RedirectResponse
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);
        abort_unless($task->course_id === $course->id && $question->navigation_task_id === $task->id, 404);
        $this->abortIfInRunningExam($request, $tenantContext, $task);

        $data = $request->validate([
            'answer' => ['nullable', 'string', 'max:500'],
            'self' => ['nullable', 'in:richtig,falsch'],
        ]);

        $key = ['tenant_id' => $tenantContext->id(), 'user_id' => $request->user()->id, 'navigation_question_id' => $question->id];
        $existing = NavigationProgress::where($key)->first();

        if (isset($data['self'])) {
            $values = ['answer' => $existing?->answer, 'correct' => $data['self'] === 'richtig'];
        } else {
            $given = trim((string) ($data['answer'] ?? ''));
            if ($given === '') {
                return back()->withErrors(['answer_'.$question->id => 'Bitte zuerst eine Antwort eingeben.'])->withFragment('frage-'.$question->question_number);
            }
            $result = NavigationAnswer::evaluate($question->answer_text, $given);
            $values = ['answer' => $given, 'correct' => $result['correct']];
        }

        NavigationProgress::updateOrCreate($key, $values + ['updated_at' => now()]);

        return redirect()->to(route('exam.navigation.show', ['course' => $course, 'task' => $task]).'#frage-'.$question->question_number);
    }

    /** @return Collection<string, NavigationProgress> Stand je Teilfrage (alle Aufgaben des Kurses). */
    private function progress(Request $request, Collection $tasks): Collection
    {
        $questionIds = NavigationQuestion::whereIn('navigation_task_id', $tasks->pluck('id'))->pluck('id');

        return NavigationProgress::where('user_id', $request->user()->id)->whereIn('navigation_question_id', $questionIds)
            ->get()->keyBy('navigation_question_id');
    }

    /** 'done' (alle Teilfragen richtig), 'started' oder null. */
    private function taskStatus(NavigationTask $task, Collection $progress): ?string
    {
        $ids = $task->questions->pluck('id');
        $rows = $ids->map(fn ($id) => $progress->get($id))->filter();
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->count() === $ids->count() && $rows->every(fn ($p) => $p->correct === true) ? 'done' : 'started';
    }
}
