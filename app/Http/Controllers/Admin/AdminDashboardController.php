<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $learnerIds = TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->pluck('user_id');
        $learnerCount = $learnerIds->count();
        $activeEntitlements = Entitlement::where('tenant_id', $tenant->id)->where('status', 'active')->count();
        $recentActivity = AuditLog::where('tenant_id', $tenant->id)->latest('created_at')->take(10)->get();

        $activeThisWeek = $learnerCount > 0
            ? Attempt::where('tenant_id', $tenant->id)
                ->whereIn('user_id', $learnerIds)
                ->where('created_at', '>=', now()->subDays(7))
                ->distinct('user_id')
                ->count('user_id')
            : 0;
        $weeklyActivityRate = $learnerCount > 0 ? (int) round($activeThisWeek / $learnerCount * 100) : 0;

        $recentExams = ExamSession::where('tenant_id', $tenant->id)
            ->where('status', 'evaluated')
            ->where('created_at', '>=', now()->subDays(30))
            ->get();
        $examPassRate = $recentExams->isNotEmpty()
            ? (int) round($recentExams->where('passed', true)->count() / $recentExams->count() * 100)
            : 0;

        return view('admin.dashboard', [
            'tenant' => $tenant,
            'learnerCount' => $learnerCount,
            'activeEntitlements' => $activeEntitlements,
            'recentActivity' => $recentActivity,
            'weeklyActivityRate' => $weeklyActivityRate,
            'recentExamsCount' => $recentExams->count(),
            'examPassRate' => $examPassRate,
        ]);
    }
}
