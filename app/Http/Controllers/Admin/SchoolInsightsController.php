<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\User;
use App\Services\CourseProgressService;
use App\Services\LearnerOverview;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Statistik der Bootsschule und vollständiges Protokoll. */
class SchoolInsightsController extends Controller
{
    public function statistics(TenantContext $tenantContext, LearnerOverview $overview): View
    {
        $tenant = $tenantContext->tenant();

        $activeLearners30 = Attempt::where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays(30))->distinct()->count('user_id');
        $answers30 = Attempt::where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays(30))->count();
        $withAccess = Entitlement::currentlyActive()->where('tenant_id', $tenant->id)->distinct()->count('user_id');

        // Aktive Lernende je Monat (letzte 6 Monate).
        $months = collect(range(5, 0))->map(fn ($i) => now('Europe/Berlin')->startOfMonth()->subMonths($i));
        $byMonth = Attempt::where('tenant_id', $tenant->id)->where('created_at', '>=', $months->first()->copy()->utc())
            ->groupBy(DB::raw("to_char(created_at AT TIME ZONE 'Europe/Berlin', 'YYYY-MM')"))
            ->selectRaw("to_char(created_at AT TIME ZONE 'Europe/Berlin', 'YYYY-MM') as month, count(distinct user_id) as learners")
            ->pluck('learners', 'month');
        $monthly = $months->map(fn ($m) => ['label' => $m->locale('de')->translatedFormat('M Y'), 'learners' => (int) ($byMonth[$m->format('Y-m')] ?? 0)]);

        $exams = ExamSession::where('exam_session.tenant_id', $tenant->id)->where('exam_session.status', 'evaluated')
            ->where('exam_session.submitted_at', '>=', now()->subDays(90))
            ->join('course_definition', 'course_definition.id', '=', 'exam_session.course_id')
            ->groupBy('course_definition.id', 'course_definition.name')
            ->selectRaw('course_definition.name, count(*) as total, sum(case when exam_session.passed then 1 else 0 end) as passed, count(distinct exam_session.user_id) as learners')
            ->orderByDesc('total')->get();

        // Durchschnittlicher Fortschritt und Prüfungsreife je Kurs (aktive Zugänge).
        $courses = Entitlement::currentlyActive()->where('tenant_id', $tenant->id)->with('course', 'user')->get()
            ->filter(fn ($e) => $e->course && $e->user)->groupBy('course_id')
            ->map(function ($group) use ($tenant, $overview) {
                $course = $group->first()->course;
                $threshold = $overview->threshold($tenant, $course);
                $percents = $group->map(fn ($e) => app(CourseProgressService::class)->overallPercent($course, $tenant, $e->user));

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
                $query->where('created_at', $operator, $filters[$key].($key === 'from' ? ' 00:00:00' : ' 23:59:59'));
            }
        }

        return view('admin.insights.audit', ['entries' => $query->paginate(50)->withQueryString(), 'filters' => $filters]);
    }
}
