<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\Progress;
use App\Models\TenantCourseDisabled;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Kurse, Zugänge, Kursübersicht, Module/Themen und Fortschritt. */
class CourseController extends Controller
{
    private const DISPLAY_ORDER = [
        'SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR', 'SRC-UBI', 'SRC', 'UBI', 'SBF-BIN-SEGEL', 'SKN', 'FKN',
        'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-UBI-TO-SRC', 'ADD-BIN-SEGEL', 'ADD-FKN-TO-SKN',
    ];

    public function __construct(
        private TenantContext $tenantContext,
        private CourseAccess $access,
        private EntitlementService $entitlements,
        private CourseProgressService $progress,
    ) {}

    /** Freigeschaltete Kurse und weitere buchbare Kurse der Schule (mit Webshop-Link). */
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $order = array_flip(self::DISPLAY_ORDER);

        $active = $this->entitlements->activeEntitlements($tenant, $user)->keyBy('course_id');
        $disabled = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');
        $offered = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)
            ->where('status', 'published')->whereNotIn('id', $disabled)->get()
            ->reject(fn ($c) => $active->has($c->id));
        $webshop = CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id');
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        // Review-Runde 13: einmal laden statt je Kurs (Startbildschirm der App).
        $entitlements = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $lastActivity = $this->lastActivityByCourse($tenant->id, $user->id);

        $courses = $active->map(fn ($e) => $e->course)->filter()->concat($offered)
            ->sortBy(fn ($c) => [$active->has($c->id) ? 0 : 1, $order[$c->code] ?? PHP_INT_MAX, $c->name])->values();

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $slice = $courses->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice->map(fn (CourseDefinition $course) => $this->summary($course, $progress, $active->get($course->id), $webshop->get($course->id), $entitlements, $lastActivity))->values(),
            'meta' => [
                'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
                'renewal_webshop_url' => $tenant->branding?->renewal_webshop_link,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $courses->count(), 'last_page' => max(1, (int) ceil($courses->count() / $perPage))],
            ],
        ]);
    }

    public function show(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $disabled = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');
        $course = CourseDefinition::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('site_enabled', true)
            ->where('status', 'published')
            ->whereNotIn('id', $disabled)
            ->find($courseId);
        if (! $course) {
            throw \App\Api\ApiProblem::notFound('Kurs');
        }
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->get();
        $course->loadMissing('modules');

        return response()->json(['data' => $this->summary($course, $progress, null, CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $course->id)->value('url')) + [
            'modules_count' => $course->modules->count(),
            'exam_options_count' => $course->examPapers()->count() ?: ($course->activeExamRuleSet() ? 1 : 0),
        ]]);
    }

    /** Kursfortschritt, Module und Wiedereinstieg („Weiterlernen“). */
    public function overview(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $modules = $this->moduleList($course);

        // Weiterlernen beim Modul der letzten Antwort in diesem Kurs.
        $last = Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->latest('created_at')->first(['question_id', 'revision_id', 'created_at']);
        $lastModule = $last ? $course->modules->first(fn ($m) => $m->questions->contains('id', $last->question_id)) : null;
        $lastRevision = $last ? ContentQuestionRevision::find($last->revision_id) : null;
        $summary = $this->summary($course, $progress, null, null);

        return response()->json(['data' => [
            'course' => $summary,
            'progress' => $summary['progress'],
            'continue_learning' => $last ? [
                'module_id' => $lastModule?->id,
                'module_name' => $lastModule?->name,
                // Review-Runde 13: „Du warst bei …“ (Thema der letzten Frage).
                'topic' => $lastRevision ? ($lastRevision->smartmodus_kategorie ?: $lastRevision->topic) : null,
                'mode' => 'smarttrainer',
                'last_activity_at' => Present::time($last->created_at),
            ] : null,
            'modules' => $modules,
        ]]);
    }

    public function modules(Request $request, string $courseId): JsonResponse
    {
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId);

        return response()->json(['data' => $this->moduleList($course)]);
    }

    /** Fortschritt gesamt, je Modul und Thema, plus Antwortzähler. */
    public function progress(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $questionIds = $this->access->validQuestionIds($course);
        $own = $progress->whereIn('question_id', $questionIds);

        return response()->json(['data' => [
            'overall' => $this->summary($course, $progress, null, null)['progress'],
            'answered_questions' => $own->where('attempt_count', '>', 0)->count(),
            'correct_answers' => (int) $own->sum('correct_count'),
            'incorrect_answers' => (int) $own->sum('incorrect_count'),
            'modules' => $this->moduleList($course),
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function moduleList(CourseDefinition $course): array
    {
        $tenant = $this->tenantContext->tenant();

        return $this->progress->moduleKategorieBreakdown($course, $tenant, request()->user())
            ->values()->map(fn ($group, $i) => [
                'id' => $group['module']->id,
                'name' => $group['module']->name,
                'description' => $group['module']->description ?? null,
                'order' => $i + 1,
                'question_count' => $group['total'],
                'progress' => ['percent' => $group['percent'], 'completed' => $group['mastered'], 'total' => $group['total']],
                'topics' => $group['topics']->map(fn ($t) => [
                    'name' => $t['topic'],
                    'question_count' => $t['total'],
                    'progress' => ['percent' => $t['percent'], 'completed' => $t['mastered'], 'total' => $t['total']],
                ])->values(),
            ])->all();
    }

    private function summary(CourseDefinition $course, Collection $progress, $activeEntitlement, ?string $webshopUrl, ?Collection $entitlements = null, ?Collection $lastActivity = null): array
    {
        $tenant = $this->tenantContext->tenant();
        $questionIds = $this->access->validQuestionIds($course);
        $total = $questionIds->count();
        $mastered = $progress->isEmpty() ? 0 : $progress->whereIn('question_id', $questionIds->all())->where('learning_state', 'gefestigt')->count();
        $lastActivity ??= $this->lastActivityByCourse($tenant->id, request()->user()->id, $course->id);

        return [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $course->name,
            'description' => $course->description ?? null,
            'access' => $this->access->status($tenant, request()->user(), $course, $activeEntitlement, $entitlements),
            // Gefestigt = 3× richtig in Folge (dieselbe Regel wie im Web).
            'progress' => ['percent' => $total > 0 ? (int) round($mastered / $total * 100) : 0, 'completed' => $mastered, 'total' => $total],
            'capabilities' => $this->access->capabilities($course),
            'webshop_url' => $webshopUrl,
            // Review-Runde 13: für „Weiterlernen“ über alle Kurse.
            'last_activity_at' => Present::time(($at = $lastActivity->get($course->id)) ? Carbon::parse($at) : null),
        ];
    }

    /** Letzte Antwort je Kurs (eine Abfrage). @return Collection<string, string> */
    private function lastActivityByCourse(string $tenantId, string $userId, ?string $courseId = null): Collection
    {
        return Attempt::where('tenant_id', $tenantId)->where('user_id', $userId)
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->groupBy('course_id')->selectRaw('course_id, max(created_at) as last_at')->pluck('last_at', 'course_id');
    }
}
