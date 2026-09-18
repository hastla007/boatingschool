<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manuelle Freischaltung laut Umsetzungskonzept: Verlängerung, Sperrung und
 * Widerruf sind eigene, auditierte Aktionen. Lernfortschritt/Attempts werden
 * dabei nie gelöscht -- nur der Entitlement-Status ändert sich.
 */
class EntitlementController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'course_id' => ['required', 'uuid'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->findOrFail($validated['course_id']);

        $entitlement = Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'valid_from' => now(),
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => 'active',
            'source_type' => 'manual',
            'source_reference' => 'admin:'.$request->user()->id,
        ]);

        AuditLog::record($tenant->id, $request->user()->id, 'entitlement.create', 'entitlement', $entitlement->id, null, $entitlement->toArray());

        return back()->with('status', 'Kurs freigeschaltet.');
    }

    public function update(Request $request, TenantContext $tenantContext, Entitlement $entitlement): Response
    {
        abort_unless($entitlement->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:extend,suspend,revoke,reactivate'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $before = $entitlement->toArray();

        match ($validated['action']) {
            'extend' => $entitlement->update(['valid_until' => $validated['valid_until']]),
            'suspend' => $entitlement->update(['status' => 'suspended']),
            'revoke' => $entitlement->update(['status' => 'revoked']),
            'reactivate' => $entitlement->update(['status' => 'active']),
        };

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'entitlement.'.$validated['action'],
            'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray()
        );

        return back()->with('status', 'Entitlement aktualisiert.');
    }
}
