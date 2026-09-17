<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class VideoCourseController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View|Response
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $moduleIds = $this->parseModuleIds($request);
        $lessons = $this->orderedLessons($course, $moduleIds);
        abort_if($lessons->isEmpty(), 404, 'Für diesen Kurs ist noch kein Videokurs hinterlegt.');

        $progress = $this->progressFor($tenantContext, $request, $lessons);
        $current = $lessons->first(fn ($lesson) => ! ($progress[$lesson->id]->completed ?? false)) ?? $lessons->first();

        return $this->show($request, $tenantContext, $entitlements, $course, $current);
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, VideoLesson $lesson): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $moduleIds = $this->parseModuleIds($request);
        $lessons = $this->orderedLessons($course, $moduleIds);
        abort_unless($lessons->contains('id', $lesson->id), 404, 'Diese Lektion gehört nicht zu diesem Kapitel.');

        $progress = $this->progressFor($tenantContext, $request, $lessons);
        $currentIndex = $lessons->search(fn ($l) => $l->id === $lesson->id);
        $next = $lessons->get($currentIndex + 1);

        $completedCount = $progress->filter(fn ($p) => $p->completed)->count();
        $lesson->load('steps');

        return view('video.show', [
            'course' => $course,
            'lesson' => $lesson,
            'lessons' => $lessons,
            'progress' => $progress,
            'next' => $next,
            'completedCount' => $completedCount,
            'totalCount' => $lessons->count(),
            'percent' => $lessons->isNotEmpty() ? (int) round($completedCount / $lessons->count() * 100) : 0,
            'moduleIds' => $moduleIds,
        ]);
    }

    public function complete(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, VideoLesson $lesson): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

        $moduleIds = $this->parseModuleIds($request);
        $lessons = $this->orderedLessons($course, $moduleIds);
        abort_unless($lessons->contains('id', $lesson->id), 404);

        VideoProgress::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'video_lesson_id' => $lesson->id],
            ['completed' => true, 'updated_at' => now()]
        );

        $currentIndex = $lessons->search(fn ($l) => $l->id === $lesson->id);
        $next = $lessons->get($currentIndex + 1);
        $kapitel = $moduleIds ? ['kapitel' => implode(',', $moduleIds)] : [];

        return redirect()->route($next ? 'video.show' : 'video.index', array_merge(
            $next ? ['course' => $course, 'lesson' => $next] : ['course' => $course],
            $kapitel
        ));
    }

    /** Eigenständige Kapitel-Seiten (z. B. "Knoten", "Praxisvideos (Motor)")
     * beschränken die Lektionsliste über ?kapitel=<module_id>[,<module_id>...]
     * auf die dort genannten Video-Module, statt den gesamten Videokurs zu
     * zeigen. */
    private function parseModuleIds(Request $request): ?array
    {
        $raw = $request->query('kapitel');

        return $raw ? array_values(array_filter(explode(',', $raw))) : null;
    }

    /** @return Collection<int, VideoLesson> */
    private function orderedLessons(CourseDefinition $course, ?array $moduleIds = null): Collection
    {
        $query = $course->videoModules()->with('lessons');

        if ($moduleIds) {
            $query->whereIn('id', $moduleIds);
        }

        return $query->get()
            ->flatMap(fn ($module) => $module->lessons->map(fn ($lesson) => tap($lesson, fn ($l) => $l->setRelation('module', $module))));
    }

    /** @return Collection<string, VideoProgress> */
    private function progressFor(TenantContext $tenantContext, Request $request, Collection $lessons): Collection
    {
        return VideoProgress::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->whereIn('video_lesson_id', $lessons->pluck('id'))
            ->get()
            ->keyBy('video_lesson_id');
    }
}
