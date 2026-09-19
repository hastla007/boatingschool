<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Progress;
use App\Models\TenantCourseDisabled;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    /**
     * Anzeigereihenfolge der Kurskacheln: erst die Vollkurse in dieser
     * festen Reihenfolge, danach alle Ergänzungskurse (nicht in dieser
     * Liste enthalten, daher automatisch ans Ende sortiert).
     */
    private const COURSE_DISPLAY_ORDER = [
        'SBF-SEE', 'SBF-BIN-MOTOR', 'SRC-UBI', 'SBF-BIN-SEGEL', 'SRC', 'UBI', 'SKN-FKN', 'SKN', 'FKN',
    ];

    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $orderIndex = array_flip(self::COURSE_DISPLAY_ORDER);

        $activeEntitlements = $entitlements->activeEntitlements($tenant, $user)->keyBy('course_id');
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $courses = $activeEntitlements->map(function ($entitlement) use ($progress) {
            $course = $entitlement->course;

            return [
                'course' => $course,
                'entitlement' => $entitlement,
                'percent' => $this->courseMasteryPercent($course, $progress),
            ];
        })->sortBy(fn ($entry) => $orderIndex[$entry['course']->code] ?? PHP_INT_MAX)->values();

        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        $lockedCourses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)
            ->whereNotIn('id', $disabledCourseIds)
            ->orderBy('name')
            ->get()
            ->reject(fn ($c) => $activeEntitlements->has($c->id))
            ->sortBy(fn ($c) => $orderIndex[$c->code] ?? PHP_INT_MAX)
            ->values();

        $webshopLinks = CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id');

        return view('courses.index', [
            'courses' => $courses,
            'lockedCourses' => $lockedCourses,
            'webshopLinks' => $webshopLinks,
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

        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
        $masteredCount = $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        return view('courses.show', [
            'course' => $course,
            'overallPercent' => $this->courseMasteryPercent($course, $progress),
            'masteredCount' => $masteredCount,
            'openCount' => max($questionIds->count() - $masteredCount, 0),
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
            'examReadinessThreshold' => $tenant->branding->exam_readiness_threshold_percent,
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
