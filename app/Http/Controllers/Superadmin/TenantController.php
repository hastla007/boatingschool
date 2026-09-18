<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Services\CourseProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TenantController extends Controller
{
    public function index(): View
    {
        $tenants = Tenant::withCount('tenantUsers')->orderBy('name')->get();

        return view('superadmin.tenants.index', ['tenants' => $tenants]);
    }

    public function create(): View
    {
        return view('superadmin.tenants.create');
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:120', 'unique:tenant,slug'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:trial,active,suspended,closed'],
            'admin_first_name' => ['required', 'string', 'max:255'],
            'admin_last_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:app_user,email'],
        ]);

        $tenant = Tenant::create([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'status' => $validated['status'],
            'default_locale' => 'de-DE',
        ]);

        TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#005FD7', 'secondary_color' => '#00A8A8']);

        $admin = \App\Models\User::create([
            'display_name' => trim($validated['admin_first_name'].' '.$validated['admin_last_name']),
            'first_name' => $validated['admin_first_name'],
            'last_name' => $validated['admin_last_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make(str()->random(32)),
            'status' => 'invited',
        ]);

        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => 'owner', 'status' => 'active']);

        return redirect()->route('superadmin.tenants.show', $tenant)->with('status', 'Bootsschule angelegt.');
    }

    public function show(Tenant $tenant, CourseProgressService $courseProgress): View
    {
        $tenant->load('branding');

        $memberships = TenantUser::where('tenant_id', $tenant->id)->with('user')->get();

        $entitlements = Entitlement::where('tenant_id', $tenant->id)->with('user', 'course')->get();

        $results = $entitlements->map(function (Entitlement $entitlement) use ($tenant, $courseProgress) {
            return [
                'entitlement' => $entitlement,
                'percent' => $courseProgress->overallPercent($entitlement->course, $tenant, $entitlement->user),
            ];
        });

        $coupons = Coupon::where('tenant_id', $tenant->id)->orWhere('redeemed_tenant_id', $tenant->id)
            ->with('course', 'product', 'redeemedBy')->orderByDesc('created_at')->get();

        return view('superadmin.tenants.show', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'results' => $results,
            'coupons' => $coupons,
        ]);
    }

    public function update(Request $request, Tenant $tenant): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:trial,active,suspended,closed'],
        ]);

        $tenant->update($validated);

        return back()->with('status', 'Bootsschule aktualisiert.');
    }
}
