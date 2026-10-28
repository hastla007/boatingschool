<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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

        $settings = PlatformSetting::current();
        // Bug-Review (Superadmin-Audit-Lücken): die plattformweiten
        // Website-Einstellungen -- insbesondere der Wartungsmodus, der
        // die gesamte Plattform für alle Mandanten sperrt (siehe
        // EnforceMaintenanceMode) -- wurden bisher gar nicht protokolliert.
        $before = $settings->only(['site_name', 'support_email', 'maintenance_mode', 'maintenance_message']);
        $settings->update($validated);
        // Bug-Review (Superadmin-Audit-Lücken): entity_id ist eine uuid-
        // Spalte, platform_setting.id dagegen die klassische Integer-Zeile
        // "1" des Singletons (siehe PlatformSetting::current()) -- anders
        // als bei den sonstigen AuditLog::record()-Aufrufen gibt es hier
        // keine passende uuid, entity_id bleibt deshalb null.
        AuditLog::record(null, $request->user()->id, 'superadmin.settings.update', 'platform_setting', null, $before, $validated);

        return back()->with('status', 'Website-Einstellungen gespeichert.');
    }
}
