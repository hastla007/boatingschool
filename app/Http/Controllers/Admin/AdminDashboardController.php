<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $learnerCount = TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->count();
        $activeEntitlements = Entitlement::where('tenant_id', $tenant->id)->where('status', 'active')->count();
        $recentActivity = AuditLog::where('tenant_id', $tenant->id)->latest('created_at')->take(10)->get();

        return view('admin.dashboard', [
            'tenant' => $tenant,
            'learnerCount' => $learnerCount,
            'activeEntitlements' => $activeEntitlements,
            'recentActivity' => $recentActivity,
        ]);
    }
}
