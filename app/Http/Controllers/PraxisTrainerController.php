<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisProgress;
use App\Models\PraxisTask;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PraxisTrainerController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View|Response
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $kategorie = $request->query('kategorie');
        $tasks = $this->orderedTasks($course, $kategorie);
        abort_if($tasks->isEmpty(), 404, 'Für diesen Kurs ist noch kein Praxistrainer hinterlegt.');

        $progress = $this->progressFor($tenantContext, $request, $tasks);
        $current = $tasks->first(fn ($task) => ! ($progress[$task->id]->completed ?? false)) ?? $tasks->first();

        return $this->show($request, $tenantContext, $entitlements, $course, $current);
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, PraxisTask $task): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $kategorie = $request->query('kategorie');
        $tasks = $this->orderedTasks($course, $kategorie);
        abort_unless($tasks->contains('id', $task->id), 404, 'Diese Aufgabe gehört nicht zu diesem Kapitel.');

        $progress = $this->progressFor($tenantContext, $request, $tasks);
        $currentIndex = $tasks->search(fn ($t) => $t->id === $task->id);
        $next = $tasks->get($currentIndex + 1);

        $completedCount = $progress->filter(fn ($p) => $p->completed)->count();
        $task->load('media');

        return view('praxistrainer.show', [
            'course' => $course,
            'task' => $task,
            'tasks' => $tasks,
            'progress' => $progress,
            'next' => $next,
            'completedCount' => $completedCount,
            'totalCount' => $tasks->count(),
            'percent' => $tasks->isNotEmpty() ? (int) round($completedCount / $tasks->count() * 100) : 0,
            'kategorie' => $kategorie,
        ]);
    }

    public function complete(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, PraxisTask $task): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

        $kategorie = $request->query('kategorie');
        $tasks = $this->orderedTasks($course, $kategorie);
        abort_unless($tasks->contains('id', $task->id), 404);

        PraxisProgress::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'praxis_task_id' => $task->id],
            ['completed' => true, 'updated_at' => now()]
        );

        $currentIndex = $tasks->search(fn ($t) => $t->id === $task->id);
        $next = $tasks->get($currentIndex + 1);
        $query = $kategorie ? ['kategorie' => $kategorie] : [];

        return redirect()->route($next ? 'praxistrainer.show' : 'praxistrainer.index', array_merge(
            $next ? ['course' => $course, 'task' => $next] : ['course' => $course],
            $query
        ));
    }

    /** @return Collection<int, PraxisTask> */
    private function orderedTasks(CourseDefinition $course, ?string $kategorie = null): Collection
    {
        $query = $course->praxisTasks();
        if ($kategorie) {
            $query->where('kategorie', $kategorie);
        }

        return $query->get();
    }

    /** @param Collection<int, PraxisTask> $tasks */
    private function progressFor(TenantContext $tenantContext, Request $request, Collection $tasks): Collection
    {
        return PraxisProgress::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->whereIn('praxis_task_id', $tasks->pluck('id'))
            ->get()
            ->keyBy('praxis_task_id');
    }
}
