<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BrandingController extends Controller
{
    public function edit(TenantContext $tenantContext): View
    {
        return view('admin.branding', ['tenant' => $tenantContext->tenant(), 'branding' => $tenantContext->tenant()->branding]);
    }

    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email'],
            'legal_name' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $tenant->branding->toArray();
        $tenant->branding->update($validated);

        AuditLog::record($tenant->id, $request->user()->id, 'branding.update', 'tenant_branding', $tenant->id, $before, $validated);

        return back()->with('status', 'Branding aktualisiert.');
    }
}
