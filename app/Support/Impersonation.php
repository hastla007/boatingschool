<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * „Als Nutzer ansehen“ (Support): der Superadmin meldet sich mit einem
 * Einmal-Link in der Sitzung der Bootsschule als die Person an. Die
 * Ansicht ist nur lesend (RestrictImpersonation), endet nach höchstens
 * 60 Minuten und jeder Start, jedes Ende und jeder blockierte
 * Änderungsversuch wird protokolliert.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    public const TOKEN_TTL_SECONDS = 60;

    public const MAX_MINUTES = 60;

    /** @return array{impersonator_id: string, impersonator_name: string, tenant_id: string, started_at: int}|null */
    public static function current(?Request $request = null): ?array
    {
        $request ??= request();
        if (! $request?->hasSession()) {
            return null;
        }

        return $request->session()->get(self::SESSION_KEY);
    }

    public static function active(?Request $request = null): bool
    {
        return self::current($request) !== null;
    }

    /**
     * Support-Ansicht beenden: abmelden nur in dieser Sitzung.
     * logout() würde das „Angemeldet bleiben“-Token der Person neu setzen
     * und sie damit auf allen ihren Geräten abmelden.
     */
    public static function endSession(Request $request): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function cacheKey(string $token): string
    {
        return 'impersonation-token:'.hash('sha256', $token);
    }
}
