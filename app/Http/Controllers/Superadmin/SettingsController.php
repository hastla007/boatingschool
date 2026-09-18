<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('superadmin.settings.edit', ['settings' => PlatformSetting::current()]);
    }

    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['maintenance_mode'] = $request->boolean('maintenance_mode');

        PlatformSetting::current()->update($validated);

        return back()->with('status', 'Website-Einstellungen gespeichert.');
    }
}
