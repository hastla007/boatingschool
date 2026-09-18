<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
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
