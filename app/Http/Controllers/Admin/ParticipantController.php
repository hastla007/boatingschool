<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\ProductPurchase;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
    public function index(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $participants = TenantUser::with(['user.entitlements' => function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)->with('course');
        }])
            ->where('tenant_id', $tenant->id)
            ->where('role', 'learner')
            ->get();

        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->orderBy('name')->get();

        $productPurchases = ProductPurchase::where('tenant_id', $tenant->id)->with('product')->get()
            ->groupBy('user_id');

        return view('admin.participants', ['participants' => $participants, 'courses' => $courses, 'productPurchases' => $productPurchases]);
    }

    public function invite(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $tenant, $request) {
            $user = User::firstOrCreate(
                ['email' => $validated['email']],
                [
                    'display_name' => $validated['name'],
                    'password' => Hash::make(Str::random(32)),
                    'status' => 'invited',
                ]
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => 'learner', 'status' => 'active']
            );

            AuditLog::record($tenant->id, $request->user()->id, 'participant.invite', 'app_user', $user->id, null, [
                'email' => $user->email,
            ]);
        });

        return back()->with('status', 'Teilnehmer eingeladen/zugeordnet.');
    }
}
