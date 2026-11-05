<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $tenants = Tenant::withCount('tenantUsers')->orderByDesc('created_at')->get();

        return view('superadmin.dashboard', [
            'tenantCount' => $tenants->count(),
            'userCount' => User::count(),
            // Bug-Review B15: status wechselt nie automatisch auf "expired"
            // -- ein reines status=active zählte abgelaufene
            // Freischaltungen weiter als aktiv.
            'activeEntitlementCount' => Entitlement::currentlyActive()->count(),
            'courseCount' => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->count(),
            'couponTotal' => Coupon::count(),
            'couponRedeemed' => Coupon::whereNotNull('redeemed_at')->count(),
            'recentTenants' => $tenants->take(5),
        ] + $this->activity($tenants));
    }

    /**
     * Kennzahlen zur Nutzung (Owner-Verbindung, plattformweit): aktive
     * Lernende der letzten 30 Tage, Bestehensquote der Prüfungssimulation je
     * Kurs (90 Tage), aktive Schulen ohne Lernaktivität (30 Tage) und
     * Kurszugänge, die in den nächsten 14 Tagen ablaufen.
     *
     * @return array<string, mixed>
     */
    private function activity($tenants): array
    {
        $since30 = now()->subDays(30);

        $activeLearners = DB::table('attempt')->where('created_at', '>=', $since30)->distinct()->count('user_id');
        $activeByTenant = DB::table('attempt')->where('created_at', '>=', $since30)
            ->groupBy('tenant_id')->selectRaw('tenant_id, count(distinct user_id) as learners')->pluck('learners', 'tenant_id');

        $examStats = DB::table('exam_session')
            ->join('course_definition', 'course_definition.id', '=', 'exam_session.course_id')
            ->where('exam_session.status', 'evaluated')->where('exam_session.submitted_at', '>=', now()->subDays(90))
            ->groupBy('course_definition.id', 'course_definition.name')
            ->selectRaw('course_definition.name, count(*) as total, sum(case when exam_session.passed then 1 else 0 end) as passed')
            ->orderByDesc('total')->get();

        $expiring = Entitlement::currentlyActive()
            ->whereNotNull('valid_until')->where('valid_until', '<=', now()->addDays(Entitlement::EXPIRY_WARNING_DAYS))
            ->count();

        return [
            'activeLearners' => $activeLearners,
            'examStats' => $examStats,
            'expiringCount' => $expiring,
            'inactiveTenants' => $tenants->where('status', 'active')->filter(fn ($t) => ! isset($activeByTenant[$t->id]))->values(),
            'activeByTenant' => $activeByTenant,
        ];
    }
}
