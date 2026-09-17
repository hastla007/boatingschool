<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\NavigationTask;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Navigationsaufgaben-Trainer: Übungsmaterial mit sofort einsehbarer
 * Musterlösung, bewusst getrennt von der strengen Prüfungssimulation
 * (siehe ExamController), da hier "Sofortauflösung" gerade gewünscht ist.
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

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, NavigationTask $task): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $tasks = $course->navigationTasks;
        abort_unless($tasks->contains('id', $task->id), 404, 'Diese Aufgabe gehört nicht zu diesem Kurs.');

        $task->load('questions');

        return view('exam.navigation', [
            'course' => $course,
            'tasks' => $tasks,
            'task' => $task,
        ]);
    }
}
