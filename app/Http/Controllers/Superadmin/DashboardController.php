<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $tenants = Tenant::withCount('tenantUsers')->orderByDesc('created_at')->get();

        return view('superadmin.dashboard', [
            'tenantCount' => $tenants->count(),
            'userCount' => User::count(),
            'activeEntitlementCount' => Entitlement::where('status', 'active')->count(),
            'courseCount' => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->count(),
            'couponTotal' => Coupon::count(),
            'couponRedeemed' => Coupon::whereNotNull('redeemed_at')->count(),
            'recentTenants' => $tenants->take(5),
        ]);
    }
}
