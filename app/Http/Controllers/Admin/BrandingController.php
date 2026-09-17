<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'logo' => ['nullable', 'image', 'max:1024'],
        ]);

        $before = $tenant->branding->toArray();
        unset($validated['logo']);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $path = $file->storeAs('branding-logos', $tenant->id.'-'.Str::random(8).'.'.$file->extension(), 'public');

            $asset = MediaAsset::create([
                'asset_key' => 'branding-logo-'.$tenant->id.'-'.Str::random(8),
                'media_type' => 'image',
                'storage_path' => Storage::disk('public')->url($path),
                'mime_type' => $file->getMimeType(),
                'rights_status' => 'licensed',
                'status' => 'published',
            ]);

            $validated['logo_asset_id'] = $asset->id;
        }

        $tenant->branding->update($validated);

        AuditLog::record($tenant->id, $request->user()->id, 'branding.update', 'tenant_branding', $tenant->id, $before, $validated);

        return back()->with('status', 'Branding aktualisiert.');
    }
}
