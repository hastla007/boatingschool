<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\PraxisFormTemplate;
use App\Models\Progress;
use App\Models\TenantAddon;
use App\Models\TenantCourseDisabled;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\ComboVideoGroups;
use App\Support\ExamRegistration;
use App\Support\FunkTraining;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    /**
     * Anzeigereihenfolge der Kurskacheln: erst die Vollkurse in dieser
     * festen Reihenfolge, danach die Ergänzungskurse in der gleichen
     * Reihenfolge wie ihre zugehörigen Vollkurse.
     */
    private const COURSE_DISPLAY_ORDER = [
        'SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR', 'SRC-UBI', 'SRC', 'UBI', 'SBF-BIN-SEGEL', 'SKN', 'FKN',
        'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-UBI-TO-SRC', 'ADD-BIN-SEGEL', 'ADD-FKN-TO-SKN',
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

        // Bug-Review (BB5: Entwurfskurse erscheinen für Lernende als
        // kaufbare Kacheln): ein Kurs im Status "draft" (noch nicht vom
        // Superadmin veröffentlicht, siehe AA15) erschien hier bisher
        // trotzdem als gesperrte, kaufbare Kachel -- site_enabled und
        // status sind unabhängige Schalter (site_enabled steuert nur, ob
        // der Kurs plattformweit überhaupt existiert, status den
        // redaktionellen Veröffentlichungsstand). Nur veröffentlichte
        // Kurse dürfen Lernenden angeboten werden.
        $lockedCourses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)
            ->where('status', 'published')
            ->whereNotIn('id', $disabledCourseIds)
            ->orderBy('name')
            ->get()
            ->reject(fn ($c) => $activeEntitlements->has($c->id))
            ->sortBy(fn ($c) => $orderIndex[$c->code] ?? PHP_INT_MAX)
            ->values();

        $webshopLinks = CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id');

        // Ein gesperrter Kurs, für den schon einmal ein Entitlement bestand,
        // das aber inzwischen zeitlich abgelaufen ist (siehe
        // CouponRedemptionService::course_validity_days), bekommt statt des
        // normalen Kauf-Hinweises den Verlängerungs-Code-Hinweis.
        //
        // Bug-Review N7: status='active' allein erfasste nur den Zeitraum
        // zwischen dem tatsächlichen Ablauf und dem nächsten Lauf des
        // ExpireEntitlements-Schedulers (der genau solche Zeilen auf
        // status='expired' umstellt) -- danach verschwand der Hinweis
        // wieder, obwohl der Zugang weiterhin abgelaufen war. 'suspended'/
        // 'revoked' bleiben bewusst ausgeschlossen: das sind administrative
        // Entscheidungen, kein zeitliches Ablaufen, ein Verlängerungscode
        // würde daran nichts ändern.
        $expiredCourseIds = Entitlement::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'expired'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->pluck('course_id');

        return view('courses.index', [
            'courses' => $courses,
            'lockedCourses' => $lockedCourses,
            'webshopLinks' => $webshopLinks,
            'expiredCourseIds' => $expiredCourseIds,
            'renewalWebshopLink' => $tenant->branding->renewal_webshop_link,
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

        // "Knoten" ist als eigene Lernbereiche-Kachel mit eigener Seite
        // ausgelagert (siehe unten) und taucht daher bewusst nicht als
        // Kapitel im sequenziellen Haupt-Videokurs bzw. dessen
        // Kursfortschritt auf.
        $mainVideoModules = $videoModules->reject(fn ($m) => $m->title === 'Knoten');
        $videoLessonIds = $mainVideoModules->flatMap->lessons->pluck('id');
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

        // Kombikurse zeigen statt einem einzelnen "Weiterlernen"-Hero eine
        // Fortschrittskarte je Teilkurs (siehe COMBO_VIDEO_GROUPS).
        $comboVideoGroups = collect(ComboVideoGroups::GROUPS[$course->code] ?? [])
            ->map(function (array $group) use ($videoModules, $chapterPercent) {
                $modules = $videoModules->whereIn('title', $group['chapters']);

                return [
                    'label' => $group['label'],
                    'motif' => $group['motif'],
                    'moduleIds' => $modules->pluck('id')->values(),
                    'percent' => $modules->isNotEmpty() ? $chapterPercent($modules->pluck('id')->all()) : 0,
                ];
            })
            ->filter(fn (array $group) => $group['moduleIds']->isNotEmpty())
            ->values();

        // Bug-Review F7: eine deaktivierte Frage taucht im Smarttrainer, im
        // Favoriten-Fragenpool und in der Prüfung nicht mehr auf -- sie
        // zählte hier aber weiter mit, sodass "gefestigt/offen" und der
        // Gesamtfortschritt nie 100% erreichen konnten (siehe auch
        // CourseProgressService::overallPercent).
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->filter(fn ($q) => $q->isCurrentlyValid())->pluck('id')->unique();
        $masteredCount = $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();
        // Bug-Review (Gates vergleichen den gerundeten Wert):
        // courseMasteryPercent() rundet für die Anzeige -- 49,5%
        // gefestigte Fragen zeigten sich bisher als "50%" und erfüllten
        // damit fälschlich eine Trigger-Schwelle von 50%. Die beiden
        // Banner-Trigger unten vergleichen deshalb den ungerundeten
        // $exactPercent, nur 'overallPercent' in der Anzeige bleibt
        // gerundet.
        $exactPercent = $this->courseMasteryExactPercent($course, $progress);
        $overallPercent = (int) round($exactPercent);

        // Nur ein Banner pro Kursseite -- bei mehreren fürs aktuelle
        // Fortschritts-Niveau freigeschalteten Addons zählt die höchste
        // Schwelle (das "größte" Angebot kommt zuerst).
        $addonBanner = TenantAddon::where('tenant_id', $tenant->id)
            ->where('trigger_course_id', $course->id)
            ->where('active', true)
            ->whereHas('addonTemplate', fn ($q) => $q->where('active', true))
            ->with('addonTemplate')
            ->get()
            ->filter(fn ($tenantAddon) => $exactPercent >= $tenantAddon->trigger_percent)
            ->sortByDesc('trigger_percent')
            ->first();

        // Von der Bootsschule selbst getexteter Empfehlungsbanner für
        // einen anderen/weiterführenden Kurs -- unabhängig vom Addon-
        // Banner oben. Ein Banner kann gleichzeitig auf mehreren
        // Kursseiten aktiv sein (course_ids), hier gated durch den
        // Fortschritt des gerade angesehenen Kurses selbst gegen
        // trigger_percent (0 = sofort sichtbar). Bei mehreren passenden
        // Bannern zählt die höchste Schwelle (das "größte" Angebot kommt
        // zuerst).
        $coursePromoBanner = CoursePromoBanner::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->whereJsonContains('course_ids', $course->id)
            ->get()
            ->filter(fn ($banner) => $exactPercent >= $banner->trigger_percent)
            ->sortByDesc('trigger_percent')
            ->first();

        return view('courses.show', [
            'course' => $course,
            'overallPercent' => $overallPercent,
            // Bug-Review T7: für den Praxis-&-Prüfung-Freischalt-Gate unten
            // in courses.blade.php -- derselbe Grund wie bei $addonBanner/
            // $coursePromoBanner oben (49,5%-Lücke durch den gerundeten
            // Wert).
            'exactPercent' => $exactPercent,
            'addonBanner' => $addonBanner,
            'coursePromoBanner' => $coursePromoBanner,
            'masteredCount' => $masteredCount,
            'openCount' => max($questionIds->count() - $masteredCount, 0),
            'hasVideoCourse' => $videoTotal > 0,
            'videoPercent' => $videoTotal > 0 ? (int) round($videoCompleted / $videoTotal * 100) : 0,
            'comboVideoGroups' => $comboVideoGroups,
            'hasExam' => $course->hasStartableExam(),
            'funkCatalogs' => FunkTraining::catalogsFor($course),
            'selfExamRegistration' => ExamRegistration::isSelf($tenant, $course),
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
            'knotenModuleId' => $knotenModule?->id,
            'knotenPercent' => $knotenModule ? $chapterPercent([$knotenModule->id]) : 0,
            'praxisModuleId' => $praxisModule?->id,
            'praxisPercent' => $praxisModule ? $chapterPercent([$praxisModule->id]) : 0,
            'navigationModuleId' => $navigationModule?->id,
            'navigationPercent' => $navigationModule ? $chapterPercent([$navigationModule->id]) : 0,
            'examReadinessThreshold' => PraxisFormTemplate::where('active', true)
                ->whereJsonContains('course_ids', $course->id)
                ->value('min_progress_percent') ?? $tenant->branding->exam_readiness_threshold_percent,
        ]);
    }

    private function courseMasteryPercent(CourseDefinition $course, Collection $progress): int
    {
        return (int) round($this->courseMasteryExactPercent($course, $progress));
    }

    /** Wie courseMasteryPercent(), aber ungerundet -- siehe Bug-Review (Gates vergleichen den gerundeten Wert) oben. */
    private function courseMasteryExactPercent(CourseDefinition $course, Collection $progress): float
    {
        // Bug-Review F7: siehe oben -- eine deaktivierte Frage darf hier
        // nicht mitzählen, sonst sind 100% nie erreichbar und die darauf
        // aufbauenden Addon-/Banner-Freigaben bleiben dauerhaft gesperrt.
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->filter(fn ($q) => $q->isCurrentlyValid())->pluck('id')->unique();
        $total = max($questionIds->count(), 1);
        $mastered = $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        return $mastered / $total * 100;
    }
}
