<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Texte einer Navigationsaufgabe pflegen: Szenario, Hinweis, Teilfragen mit
 * Musterlösung (Teilfragen ergänzen und entfernen). Kombikurse haben Kopien
 * der Aufgaben (ComboNavigationTaskSeeder); die Änderung lässt sich auf
 * Kopien mit gleicher Nummer und gleichem bisherigen Szenario übertragen.
 */
class NavigationTaskEditorController extends Controller
{
    public function edit(NavigationTask $task): View
    {
        $task->load('questions', 'course');

        return view('superadmin.navigation.edit', ['task' => $task, 'copies' => $this->copies($task)]);
    }

    public function update(Request $request, NavigationTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'scenario_text' => ['required', 'string', 'max:5000'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'questions' => ['array'],
            'questions.*.id' => ['nullable', 'uuid'],
            'questions.*.question_text' => ['nullable', 'string', 'max:2000'],
            'questions.*.answer_text' => ['nullable', 'string', 'max:2000'],
            'questions.*.delete' => ['nullable', 'boolean'],
            'apply_to_copies' => ['nullable', 'boolean'],
        ]);

        $rows = collect($validated['questions'] ?? []);
        foreach ($rows as $row) {
            $filled = trim((string) ($row['question_text'] ?? '')) !== '' || trim((string) ($row['answer_text'] ?? '')) !== '';
            if (empty($row['delete']) && $filled && (trim((string) ($row['question_text'] ?? '')) === '' || trim((string) ($row['answer_text'] ?? '')) === '')) {
                return back()->withErrors(['questions' => 'Jede Teilfrage braucht Frage und Musterlösung.'])->withInput();
            }
        }

        $targets = collect([$task])->merge($request->boolean('apply_to_copies') ? $this->copies($task) : []);
        $before = ['scenario_text' => $task->scenario_text, 'questions' => $task->questions()->count()];

        DB::transaction(function () use ($targets, $validated, $rows, $task) {
            $original = $task->questions()->get()->keyBy('id');
            // Bisherige Nummern merken: das Original wird in der Schleife
            // umnummeriert, die Kopien müssen aber nach der alten Nummer
            // zugeordnet werden.
            $originalNumbers = $original->map(fn ($q) => $q->question_number);
            foreach ($targets as $target) {
                $target->update(['scenario_text' => trim($validated['scenario_text']), 'hint' => trim((string) ($validated['hint'] ?? '')) ?: null]);
                $existing = $target->questions()->get();
                $number = 0;
                foreach ($rows as $row) {
                    $source = isset($row['id']) ? $original->get($row['id']) : null;
                    // Die passende Teilfrage der Kopie: gleiche Nummer wie das Original.
                    $question = $source ? ($target->is($task) ? $source : $existing->firstWhere('question_number', $originalNumbers[$source->id])) : null;
                    if (! empty($row['delete']) || trim((string) ($row['question_text'] ?? '')) === '') {
                        $question?->delete();

                        continue;
                    }
                    $number++;
                    $values = ['question_number' => $number, 'sort_order' => $number, 'question_text' => trim($row['question_text']), 'answer_text' => trim($row['answer_text'])];
                    $question ? $question->update($values) : NavigationQuestion::create($values + ['navigation_task_id' => $target->id]);
                }
            }
        });

        AuditLog::record(null, $request->user()->id, 'superadmin.navigation_task.update', 'navigation_task', $task->id, $before, [
            'task_number' => $task->task_number, 'questions' => $task->questions()->count(), 'copies' => $targets->count() - 1,
        ]);

        return redirect()->route('superadmin.navigation.index', $task->course_id)
            ->with('status', "Aufgabe {$task->task_number} gespeichert".($targets->count() > 1 ? ', auch in '.($targets->count() - 1).' Kopie(n).' : '.'));
    }

    /** @return Collection<int, NavigationTask> Aufgaben anderer Kurse mit gleicher Nummer und gleichem Szenario. */
    private function copies(NavigationTask $task): Collection
    {
        return NavigationTask::where('id', '!=', $task->id)->where('task_number', $task->task_number)
            ->where('scenario_text', $task->scenario_text)->with('course:id,name')->get();
    }
}
