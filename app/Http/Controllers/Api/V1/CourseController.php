<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\CourseFile;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\Progress;
use App\Models\VideoProgress;
use App\Models\NavigationProgress;
use App\Models\PraxisProgress;
use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use App\Support\Geraetesimulation;
use App\Models\TenantCourseDisabled;
use App\Services\CourseProgressService;
use App\Services\CourseRelationService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        private CourseRelationService $courseRelations,
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
        $relations = $this->courseRelations->forCourses($tenant, $user, $slice);

        return response()->json([
            'data' => $slice->map(fn (CourseDefinition $course) => $this->summary($course, $progress, $active->get($course->id), $webshop->get($course->id), $entitlements, $lastActivity, $relations[$course->code] ?? null))->values(),
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
        $user = $request->user();
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

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $course->loadMissing('modules', 'files');
        $files = $course->files->where('published', true)->values();
        $downloadAllowedIds = $this->access->limitItems(
            $tenant, $user, $course, 'downloads',
            $files->where('category', 'downloads')->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values(),
        )->pluck('id')->flip();
        $fileRef = function (CourseFile $file) use ($tenant, $user, $downloadAllowedIds): array {
            $allowed = $file->category === 'downloads'
                ? $downloadAllowedIds->has($file->id)
                : ($file->required_capability === null
                    ? $this->entitlements->hasAccess($tenant, $user, $file->course)
                    : $this->access->hasFeatureAccess($tenant, $user, $file->course, $file->required_capability));

            return [
                'id' => $file->id,
                'title' => $file->title,
                'description' => $file->description,
                'size_bytes' => $file->size_bytes,
                'locked' => ! $allowed,
                'url' => $allowed ? route('api.v1.files.download', ['fileId' => $file->id]) : null,
                'requires_authorization' => true,
            ];
        };
        $sections = $files->where('category', 'section')->groupBy(fn (CourseFile $file) => $file->section_key ?: 'general')
            ->map(fn ($items) => $items->map($fileRef)->values())->all();

        $summary = $this->summary($course, $progress, null, CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $course->id)->value('url'));
        $hasExam = $this->access->hasFeatureAccess($tenant, $user, $course, 'exam');
        $hasVideo = $this->access->hasFeatureAccess($tenant, $user, $course, 'video');
        $hasNavigation = $this->access->hasFeatureAccess($tenant, $user, $course, 'navigation');
        $hasPraxis = $this->access->hasFeatureAccess($tenant, $user, $course, 'praxis');
        $hasRadio = $this->access->hasFeatureAccess($tenant, $user, $course, 'funk');

        $examOptions = $hasExam ? ($course->examPapers()->count() ?: ($course->activeExamRuleSet() ? 1 : 0)) : 0;
        $favoriteCount = 0;
        if ($this->access->hasFeatureAccess($tenant, $user, $course, 'smarttrainer')) {
            $favoriteCount = DB::table('favorite')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->whereIn('question_id', $this->access->accessibleQuestionIds($tenant, $user, $course))->count();
        }

        $navigationTasks = $hasNavigation ? $course->navigationTasks()->with('questions')->get() : collect();
        $navigationQuestionIds = $navigationTasks->flatMap(fn ($task) => $task->questions->pluck('id'))->values();
        $navigationProgress = $navigationQuestionIds->isEmpty() ? collect() : NavigationProgress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->whereIn('navigation_question_id', $navigationQuestionIds)->get()->keyBy('navigation_question_id');
        $navigationDone = $navigationTasks->filter(fn ($task) => $task->questions->isNotEmpty()
            && $task->questions->every(fn ($question) => $navigationProgress->get($question->id)?->correct === true))->count();

        $praxisTasks = $hasPraxis ? $course->praxisTasks()->where('active', true)->get() : collect();
        $praxisProgress = $praxisTasks->isEmpty() ? collect() : PraxisProgress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->whereIn('praxis_task_id', $praxisTasks->pluck('id'))->get();
        $praxisKnown = $praxisProgress->filter(fn ($row) => $row->status === 'known' || $row->completed)->count();

        $videoModules = $course->videoModules()->with('lessons')->get();
        $videoLessons = $videoModules->flatMap->lessons;
        $videoProgress = $hasVideo && $videoLessons->isNotEmpty() ? VideoProgress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->whereIn('video_lesson_id', $videoLessons->pluck('id'))->get() : collect();
        $videoCompleted = $videoProgress->where('completed', true)->count();
        $knotModule = $videoModules->firstWhere('title', 'Knoten');
        $knotLessons = $knotModule?->lessons ?? collect();
        $knotProgress = $knotLessons->isEmpty() ? collect() : $videoProgress->whereIn('video_lesson_id', $knotLessons->pluck('id'));
        $knots = $knotModule ? [
            'available' => true, 'locked' => ! $hasVideo, 'module_id' => $knotModule->id,
            'lessons' => $knotLessons->count(), 'completed' => $hasVideo ? $knotProgress->where('completed', true)->count() : 0,
            'unlock_at' => null, 'webshop_url' => ! $hasVideo ? $summary['webshop_url'] : null,
        ] : null;

        $radioCatalogs = $hasRadio ? FunkTraining::catalogsFor($course) : [];
        $radioTexts = $radioVocabulary = $radioPractice = 0;
        foreach ($radioCatalogs as $catalog) {
            $radioTexts += count(FunkTraining::texts($catalog));
            if ($catalog === 'UBI') {
                $radioTexts += count(FunkTraining::ubiTexts('aufnahme')) + count(FunkTraining::ubiTexts('abgabe'));
            }
            foreach (FunkTraining::vocabulary($catalog) as $cards) {
                $radioVocabulary += count($cards);
            }
            $radioPractice += count(FunkPraxis::tasks($catalog));
        }
        $deviceAvailable = Geraetesimulation::availableFor($user, $course);
        $deviceCatalogs = Geraetesimulation::catalogsFor($course);
        $deviceLessons = Geraetesimulation::lessonsForBrowser($deviceCatalogs);
        $offerCapability = collect($summary['capabilities'])->reject(fn ($capability) => $capability === 'favorites')
            ->first(fn ($capability) => ! ($summary['features'][$capability]['available'] ?? false), 'smarttrainer');
        $offers = $summary['webshop_url'] ? [
            $offerCapability => [['name' => $course->name, 'url' => $summary['webshop_url'], 'price' => '']],
        ] : [];

        return response()->json(['data' => $summary + [
            'modules_count' => $course->modules->count(),
            'exam_options_count' => $examOptions,
            'favorites_count' => $favoriteCount,
            'trainers' => [
                'navigation' => ['tasks' => $navigationTasks->count(), 'questions' => $navigationQuestionIds->count(), 'done' => $navigationDone],
                'praxis' => ['cards' => $praxisTasks->count(), 'known' => $praxisKnown],
                'video' => ['lessons' => $hasVideo ? $videoLessons->count() : 0, 'completed' => $videoCompleted],
                'radio' => ['texts' => $radioTexts, 'vocabulary' => $radioVocabulary, 'practice' => $radioPractice, 'device' => $deviceAvailable ? count($deviceLessons) : 0],
            ],
            'offers' => (object) $offers,
            'downloads' => $files->where('category', 'download')->map($fileRef)->values(),
            'section_files' => (object) $sections,
            'charts' => $files->where('category', 'chart')->map(fn (CourseFile $file) => ['code' => $file->section_key ?: $file->id] + $fileRef($file))->values(),
            'web_url' => route('courses.show', ['course' => $course]),
            'device_simulator' => [
                'available' => $deviceAvailable, 'premium' => false, 'tier' => $deviceAvailable ? 'free' : null,
                'web_url' => $deviceAvailable ? route('funk.device.index', ['course' => $course]) : null,
                'open_in' => 'browser',
                'hint' => $deviceAvailable ? 'Der Funkgerät-Simulator wird im Browser geöffnet.' : null,
                'webshop_url' => null,
            ],
            'knots' => $knots,
            'exam_registration' => [
                'available' => \App\Support\ExamBooking::pagesFor($course) !== [],
                'web_url' => \App\Support\ExamBooking::pagesFor($course) !== [] ? route('exam-booking', ['course' => $course]) : null,
            ],
        ]]);
    }

    /** Kursfortschritt, Module und Wiedereinstieg („Weiterlernen“). */
    public function overview(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'smarttrainer');
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
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId, 'smarttrainer');

        return response()->json(['data' => $this->moduleList($course)]);
    }

    /** Fortschritt gesamt, je Modul und Thema, plus Antwortzähler. */
    public function progress(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'smarttrainer');
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $questionIds = $this->access->accessibleQuestionIds($tenant, $user, $course);
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

        return $this->progress->moduleKategorieBreakdown($course, $tenant, request()->user(), $this->access->accessibleQuestionIds($tenant, request()->user(), $course))
            ->values()->map(fn ($group, $i) => [
                'id' => $group['module']->id,
                'name' => $group['module']->name,
                'description' => $group['module']->description ?? null,
                'order' => $i + 1,
                'locked' => $this->access->isDripLocked($tenant, request()->user(), $course, 'smarttrainer', (string) $group['module']->code),
                'unlock_at' => Present::time($this->access->dripUnlockAt($tenant, request()->user(), $course, 'smarttrainer', (string) $group['module']->code)),
                'question_count' => $group['total'],
                'progress' => ['percent' => $group['percent'], 'completed' => $group['mastered'], 'total' => $group['total']],
                'topics' => $group['topics']->map(fn ($t) => [
                    'name' => $t['topic'],
                    'question_count' => $t['total'],
                    'progress' => ['percent' => $t['percent'], 'completed' => $t['mastered'], 'total' => $t['total']],
                ])->values(),
            ])->all();
    }

    private function summary(CourseDefinition $course, Collection $progress, $activeEntitlement, ?string $webshopUrl, ?Collection $entitlements = null, ?Collection $lastActivity = null, ?array $relation = null): array
    {
        $tenant = $this->tenantContext->tenant();
        $user = request()->user();
        $canLearn = $this->access->hasFeatureAccess($tenant, $user, $course, 'smarttrainer');
        $questionIds = $canLearn ? $this->access->accessibleQuestionIds($tenant, $user, $course) : collect();
        $ownProgress = $progress->whereIn('question_id', $questionIds->all());
        $total = $questionIds->count();
        $mastered = $ownProgress->where('learning_state', 'gefestigt')->count();
        $new = max(0, $total - $ownProgress->count());
        $unsure = $ownProgress->where('learning_state', 'unsicher')->count();
        $learning = $ownProgress->where('learning_state', 'in_bearbeitung')->count();
        $lastActivity ??= $this->lastActivityByCourse($tenant->id, $user->id, $course->id);
        $capabilities = $this->access->capabilities($course);
        $access = $this->access->status($tenant, $user, $course, $activeEntitlement, $entitlements);
        $features = collect($capabilities)->reject(fn (string $capability) => $capability === 'favorites')
            ->mapWithKeys(function (string $capability) use ($tenant, $user, $course): array {
                $scope = $this->access->featureScope($tenant, $user, $course, $capability);
                $limit = $scope['full'] ? null : ((int) ($scope['limit'] ?? 0) ?: null);

                return [$capability => ['available' => $scope['full'] || $limit !== null, 'full' => $scope['full'], 'limit' => $limit]];
            })->all();
        $explanations = $this->access->featureScope($tenant, $user, $course, 'explanations');
        $explanationLimit = $explanations['full'] ? null : ((int) ($explanations['limit'] ?? 0) ?: null);
        $features['explanations'] = [
            'available' => $explanations['full'] || $explanationLimit !== null,
            'full' => $explanations['full'],
            'limit' => null,
        ];
        $relation ??= $this->courseRelations->forCourses($tenant, $user, collect([$course]))[$course->code];
        $faqCount = $canLearn ? (int) DB::table('customer_faq_category_item as item')
            ->join('customer_faq_category as category', 'category.id', '=', 'item.category_id')
            ->join('customer_faq as faq', 'faq.id', '=', 'item.faq_id')
            ->where('category.course_code', $course->code)->where('faq.status', 'published')
            ->distinct()->count('faq.id') : 0;

        return [
            'id' => $course->id,
            'code' => $course->code,
            'slug' => strtolower((string) $course->code),
            'name' => $course->name,
            'description' => $course->description ?? null,
            'course_type' => $course->course_type,
            'access' => $access,
            'progress' => [
                'percent' => $total > 0 ? (int) round($mastered / $total * 100) : 0,
                'completed' => $mastered, 'total' => $total,
                'new' => $new, 'unsure' => $unsure, 'learning' => $learning, 'mastered' => $mastered,
            ],
            'capabilities' => $capabilities,
            'features' => $features,
            'webshop_url' => $access['status'] === 'active' && ! $access['trial'] ? null : $webshopUrl,
            'relation' => $relation,
            'faq_count' => $faqCount,
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
