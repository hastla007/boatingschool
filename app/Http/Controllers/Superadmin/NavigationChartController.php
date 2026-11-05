<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\NavigationTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kartenausschnitte zu den Navigationsaufgaben. Die amtliche
 * Übungsseekarte (BSH) ist urheberrechtlich geschützt und deshalb nicht
 * Teil der App: hochgeladen wird nur lizenziertes Material, mit
 * Bildunterschrift (Quelle/Lizenz).
 */
class NavigationChartController extends Controller
{
    public function index(CourseDefinition $course): View
    {
        $tasks = $course->navigationTasks()->withCount('questions')->get();
        abort_if($tasks->isEmpty(), 404);

        return view('superadmin.navigation.index', ['course' => $course, 'tasks' => $tasks]);
    }

    public function update(Request $request, NavigationTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'chart' => [$task->chart_image_path ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'chart_caption' => ['nullable', 'string', 'max:500'],
        ]);

        $values = ['chart_caption' => $validated['chart_caption'] ?? null];
        $old = $task->chart_image_path;
        if ($request->hasFile('chart')) {
            $file = $request->file('chart');
            $values['chart_image_path'] = $file->storeAs('navigation-charts', $task->id.'-'.Str::random(8).'.'.$file->extension(), 'public');
        }
        $task->update($values);
        if ($old && isset($values['chart_image_path'])) {
            Storage::disk('public')->delete($old);
        }
        AuditLog::record(null, $request->user()->id, 'superadmin.navigation_chart.update', 'navigation_task', $task->id, null, ['task_number' => $task->task_number]);

        return redirect()->to(route('superadmin.navigation.index', $task->course_id).'#aufgabe-'.$task->task_number)
            ->with('status', "Kartenausschnitt zu Aufgabe {$task->task_number} gespeichert.");
    }

    public function destroy(Request $request, NavigationTask $task): RedirectResponse
    {
        if ($task->chart_image_path) {
            Storage::disk('public')->delete($task->chart_image_path);
        }
        $task->update(['chart_image_path' => null, 'chart_caption' => null]);
        AuditLog::record(null, $request->user()->id, 'superadmin.navigation_chart.delete', 'navigation_task', $task->id, null, ['task_number' => $task->task_number]);

        return redirect()->to(route('superadmin.navigation.index', $task->course_id).'#aufgabe-'.$task->task_number)
            ->with('status', "Kartenausschnitt zu Aufgabe {$task->task_number} entfernt.");
    }
}
