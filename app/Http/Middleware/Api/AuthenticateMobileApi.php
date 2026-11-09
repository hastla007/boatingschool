<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use App\Api\DeviceSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-Token einer Gerätesitzung prüfen. Gesperrte Konten verlieren den
 * Zugang sofort; die Sitzung steht danach für Logout/Gerätelisten bereit.
 */
class AuthenticateMobileApi
{
    public function __construct(private DeviceSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = is_string($token) && $token !== '' ? $this->sessions->findByAccessToken($token) : null;
        $user = $session?->user;

        if (! $session || ! $user || $user->status !== 'active') {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Anmeldung erforderlich', 'Sitzung ungültig oder abgelaufen.');
        }

        // Für die RLS-Policy auf app_user (eigene Zeile sichtbar).
        DB::statement("SET app.current_user_id = '{$user->id}'");

        Auth::shouldUse('mobile');
        Auth::guard('mobile')->setUser($user);
        $request->attributes->set('api_session', $session);
        $request->setUserResolver(fn () => $user);

        // „Zuletzt verwendet“ höchstens einmal je Minute schreiben.
        if ($session->last_used_at === null || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
