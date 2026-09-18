<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'contact_first_name' => ['nullable', 'string', 'max:255'],
            'contact_last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            'website' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'exam_readiness_threshold_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $before = $tenant->branding->toArray();
        unset($validated['logo']);

        $supportEmailChanged = $tenant->branding->support_email !== ($validated['support_email'] ?? null);
        if ($supportEmailChanged) {
            $validated['support_email_verified_at'] = null;
        }

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

        $emailChangedToVerify = $supportEmailChanged && ! empty($validated['support_email']);

        $tenant->branding->update($validated);

        if ($emailChangedToVerify) {
            SupportEmailVerificationController::sendVerificationMail($tenant->fresh());
        }

        AuditLog::record($tenant->id, $request->user()->id, 'branding.update', 'tenant_branding', $tenant->id, $before, $validated);

        return back()->with('status', 'Branding aktualisiert.');
    }
}
