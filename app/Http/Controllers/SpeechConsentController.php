<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einwilligung in die Spracherkennung des Browsers (Funk-Übungen und
 * Gerätesimulation): Chrome/Edge schicken die Aufnahme an Google, Safari an
 * Apple. Ohne Einwilligung bleibt die Funktion aus; sie wird vor der ersten
 * Nutzung im Dialog erfragt (funk/_speech-consent) und lässt sich im Profil
 * widerrufen. Gespeichert serverseitig (app_user.speech_consent_at) und
 * protokolliert, damit sie nachweisbar ist.
 */
class SpeechConsentController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext): Response
    {
        $user = $request->user();
        if ($user->speech_consent_at === null) {
            $user->forceFill(['speech_consent_at' => now()])->save();
            AuditLog::record($tenantContext->id(), $user->id, 'profile.speech_consent.granted', 'app_user', $user->id);
        }

        return $request->expectsJson()
            ? response()->json(['granted' => true])
            : back()->with('status', 'speech-consent-updated');
    }

    public function destroy(Request $request, TenantContext $tenantContext): Response
    {
        $user = $request->user();
        if ($user->speech_consent_at !== null) {
            $user->forceFill(['speech_consent_at' => null])->save();
            AuditLog::record($tenantContext->id(), $user->id, 'profile.speech_consent.revoked', 'app_user', $user->id);
        }

        return $request->expectsJson()
            ? response()->json(['granted' => false])
            : back()->with('status', 'speech-consent-updated');
    }
}
