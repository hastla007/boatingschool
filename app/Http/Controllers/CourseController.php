<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $activeEntitlements = $entitlements->activeEntitlements($tenant, $user)->keyBy('course_id');
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $courses = $activeEntitlements->map(function ($entitlement) use ($progress) {
            $course = $entitlement->course;

            return [
                'course' => $course,
                'entitlement' => $entitlement,
                'percent' => $this->courseMasteryPercent($course, $progress),
            ];
        })->values();

        $lockedCourses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->orderBy('name')
            ->get()
            ->reject(fn ($c) => $activeEntitlements->has($c->id))
            ->values();

        return view('courses.index', [
            'courses' => $courses,
            'lockedCourses' => $lockedCourses,
        ]);
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View|Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        if (! $entitlements->hasAccess($tenant, $user, $course)) {
            abort(403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');
        }

        $course->load('modules.questions.revisions');

        // Eloquent-Collection::only() arbeitet über den Primärschlüssel des
        // Models; Progress hat aber einen zusammengesetzten Schlüssel ohne
        // einzelne id-Spalte, daher hier bewusst whereIn() auf dem Attribut.
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $modules = $course->modules->map(function ($module) use ($progress) {
            $questionIds = $module->questions->pluck('id');
            $moduleProgress = $progress->whereIn('question_id', $questionIds);
            $total = max($questionIds->count(), 1);
            $mastered = $moduleProgress->where('learning_state', 'gefestigt')->count();

            return [
                'module' => $module,
                'total' => $questionIds->count(),
                'mastered' => $mastered,
                'percent' => (int) round(($mastered / $total) * 100),
            ];
        });

        $videoModules = $course->videoModules()->with('lessons')->get();
        $videoLessonIds = $videoModules->flatMap->lessons->pluck('id');
        $videoTotal = $videoLessonIds->count();
        $videoCompleted = $videoTotal > 0
            ? VideoProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->whereIn('video_lesson_id', $videoLessonIds)->where('completed', true)->count()
            : 0;

        // Einzelne Videokurs-Kapitel als eigenständige Seiten: "Knoten",
        // "Praxisvideos (Motor)" und "Navigation" bekommen jeweils ihre
        // eigene, auf ihr Kapitel beschränkte Ansicht (?kapitel=<module_id>).
        $knotenModule = $videoModules->firstWhere('title', 'Knoten');
        $praxisModule = $videoModules->firstWhere('title', 'Praxisvideos (Motor)');
        $navigationModule = $videoModules->firstWhere('title', 'Navigation');

        $chapterPercent = function (array $moduleIds) use ($tenant, $user, $videoModules) {
            $lessonIds = $videoModules->whereIn('id', $moduleIds)->flatMap->lessons->pluck('id');
            if ($lessonIds->isEmpty()) {
                return 0;
            }
            $completed = VideoProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->whereIn('video_lesson_id', $lessonIds)->where('completed', true)->count();

            return (int) round($completed / $lessonIds->count() * 100);
        };

        return view('courses.show', [
            'course' => $course,
            'modules' => $modules,
            'overallPercent' => $this->courseMasteryPercent($course, $progress),
            'hasVideoCourse' => $videoTotal > 0,
            'videoPercent' => $videoTotal > 0 ? (int) round($videoCompleted / $videoTotal * 100) : 0,
            'hasExam' => (bool) $course->activeExamRuleSet(),
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
            'knotenModuleId' => $knotenModule?->id,
            'knotenPercent' => $knotenModule ? $chapterPercent([$knotenModule->id]) : 0,
            'praxisModuleId' => $praxisModule?->id,
            'praxisPercent' => $praxisModule ? $chapterPercent([$praxisModule->id]) : 0,
            'navigationModuleId' => $navigationModule?->id,
            'navigationPercent' => $navigationModule ? $chapterPercent([$navigationModule->id]) : 0,
        ]);
    }

    private function courseMasteryPercent(CourseDefinition $course, Collection $progress): int
    {
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
        $total = max($questionIds->count(), 1);
        $mastered = $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        return (int) round($mastered / $total * 100);
    }
}
