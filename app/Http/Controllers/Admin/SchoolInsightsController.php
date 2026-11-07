<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CourseProgressService;
use App\Services\LearnerOverview;
use App\Support\InstructorScope;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Statistik der Bootsschule und vollständiges Protokoll. */
class SchoolInsightsController extends Controller
{
    public function statistics(Request $request, TenantContext $tenantContext, LearnerOverview $overview): View
    {
        $tenant = $tenantContext->tenant();
        // Ausbilder mit zugeordneten Kursen: nur deren Lernende und Kurse.
        $visible = InstructorScope::learnerIds($tenant, $request->user());
        $scopeCourses = InstructorScope::courseIds($tenant, $request->user());
        // Ein Array-Parameter statt einer Liste einzelner Platzhalter (Postgres erlaubt höchstens 65.535).
        $visibleArray = $visible === null ? null : '{'.$visible->implode(',').'}';
        $learners = fn ($q, string $column = 'user_id') => $q->when($visibleArray !== null, fn ($w) => $w->whereRaw("{$column} = any(?::uuid[])", [$visibleArray]));

        // Basis: aktive Lernende (Mitgliedschaft) mit laufendem Kurszugang;
        // „aktiv“ heißt irgendeine Lernaktivität (Fragen, Prüfungssimulation,
        // Navigationsaufgaben, Videos), nicht nur Antworten im Fragentrainer.
        // Als Unterabfragen statt ID-Listen: bei vielen Lernenden würde jede
        // ID ein eigener Platzhalter (Postgres erlaubt höchstens 65.535).
        $memberIds = TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->where('status', 'active')->select('user_id');
        $accessIds = $learners(Entitlement::currentlyActive()->where('tenant_id', $tenant->id)->whereIn('user_id', $memberIds)
            ->when($scopeCourses !== null, fn ($q) => $q->whereIn('course_id', $scopeCourses)))->select('user_id');
        $withAccess = DB::query()->fromSub($accessIds, 'access')->distinct()->count('user_id');
        $events = function ($since) use ($tenant, $memberIds, $learners) {
            $source = fn (string $table, string $column) => $learners(DB::table($table)->where('tenant_id', $tenant->id)->whereIn('user_id', $memberIds))
                ->where($column, '>=', $since)->selectRaw("user_id, {$column} as at");

            return DB::query()->fromSub($source('attempt', 'created_at')
                ->unionAll($source('exam_session', 'started_at'))
                ->unionAll($source('navigation_progress', 'updated_at'))
                ->unionAll($source('video_progress', 'updated_at')), 'activity');
        };
        $activeLearners30 = $events(now()->subDays(30))->whereIn('user_id', $accessIds)->distinct()->count('user_id');
        $answers30 = $learners(Attempt::where('tenant_id', $tenant->id)->whereIn('user_id', $memberIds)->where('created_at', '>=', now()->subDays(30)))->count();

        // Aktive Lernende je Monat (letzte 6 Monate).
        $months = collect(range(5, 0))->map(fn ($i) => now('Europe/Berlin')->startOfMonth()->subMonths($i));
        $byMonth = $events($months->first()->copy()->utc())
            ->groupBy(DB::raw("to_char(at AT TIME ZONE 'Europe/Berlin', 'YYYY-MM')"))
            ->selectRaw("to_char(at AT TIME ZONE 'Europe/Berlin', 'YYYY-MM') as month, count(distinct user_id) as learners")
            ->pluck('learners', 'month');
        $monthly = $months->map(fn ($m) => ['label' => $m->locale('de')->translatedFormat('M Y'), 'learners' => (int) ($byMonth[$m->format('Y-m')] ?? 0)]);

        $exams = $learners(ExamSession::where('exam_session.tenant_id', $tenant->id), 'exam_session.user_id')->where('exam_session.status', 'evaluated')
            ->when($scopeCourses !== null, fn ($q) => $q->whereIn('exam_session.course_id', $scopeCourses))
            ->where('exam_session.submitted_at', '>=', now()->subDays(90))
            ->join('course_definition', 'course_definition.id', '=', 'exam_session.course_id')
            ->groupBy('course_definition.id', 'course_definition.name')
            ->selectRaw('course_definition.name, count(*) as total, sum(case when exam_session.passed then 1 else 0 end) as passed, count(distinct exam_session.user_id) as learners')
            ->orderByDesc('total')->get();

        // Durchschnittlicher Fortschritt und Prüfungsreife je Kurs (aktive Zugänge).
        $courses = $learners(Entitlement::currentlyActive()->where('tenant_id', $tenant->id)->when($scopeCourses !== null, fn ($q) => $q->whereIn('course_id', $scopeCourses)))->with('course', 'user')->get()
            ->filter(fn ($e) => $e->course && $e->user)->groupBy('course_id')
            ->map(function ($group) use ($tenant, $overview) {
                $course = $group->first()->course;
                $threshold = $overview->threshold($tenant, $course);
                $byUser = app(CourseProgressService::class)->overallPercents($course, $tenant, $group->pluck('user_id'));
                $percents = $group->map(fn ($e) => $byUser[$e->user_id] ?? 0);

                return [
                    'name' => $course->name,
                    'learners' => $group->count(),
                    'average' => (int) round($percents->avg()),
                    'ready' => $percents->filter(fn ($p) => $p >= $threshold)->count(),
                ];
            })->sortBy('name')->values();

        return view('admin.insights.statistics', compact('activeLearners30', 'answers30', 'withAccess', 'monthly', 'exams', 'courses'));
    }

    public function audit(Request $request, TenantContext $tenantContext): View
    {
        $param = fn (string $key) => is_string($request->query($key)) ? trim($request->query($key)) : '';
        $filters = ['action' => $param('action'), 'actor' => $param('actor'), 'from' => $param('from'), 'to' => $param('to')];

        $query = AuditLog::where('tenant_id', $tenantContext->id())->orderByDesc('created_at')->orderByDesc('id');
        if ($filters['action'] !== '') {
            $query->where('action', 'ilike', '%'.$filters['action'].'%');
        }
        if ($filters['actor'] !== '') {
            $query->whereIn('actor_user_id', User::where('email', 'ilike', '%'.$filters['actor'].'%')->select('id'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                // Datum gilt in deutscher Zeit, gespeichert wird UTC.
                $day = Carbon::parse($filters[$key], 'Europe/Berlin');
                $query->where('created_at', $operator, ($key === 'from' ? $day->startOfDay() : $day->endOfDay())->utc());
            }
        }

        return view('admin.insights.audit', ['entries' => $query->paginate(50)->withQueryString(), 'filters' => $filters]);
    }
}
