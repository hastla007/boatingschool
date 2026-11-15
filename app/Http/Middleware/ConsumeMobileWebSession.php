<?php

namespace App\Http\Middleware;

use App\Api\DeviceSessions;
use App\Http\Controllers\Api\V1\WebSessionController;
use App\Models\ApiDeviceSession;
use App\Models\ApiMobileWebSession;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Löst den Einmal-Code der App für die Webansicht ein (siehe
 * WebSessionController): läuft in der web-Gruppe direkt nach ResolveTenant
 * (Schule und RLS-Kontext stehen fest) und vor der Anmeldeprüfung. Der
 * Code gilt nur in der Schule, für die er ausgestellt wurde, nur einmal,
 * nur 60 Sekunden und nur mit dem passenden Verifier-Header. Danach geht es
 * immer per Weiterleitung auf dieselbe Adresse ohne Code weiter (Code
 * nicht in der Adresszeile, kein offenes Weiterleitungsziel).
 */
class ConsumeMobileWebSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->query->has(WebSessionController::CODE_PARAM)) {
            return $next($request);
        }

        $code = $request->query(WebSessionController::CODE_PARAM);
        $query = $request->query();
        unset($query[WebSessionController::CODE_PARAM]);
        $cleanUrl = $request->url().($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        $user = is_string($code) ? $this->consume($request, $code) : null;
        if ($user) {
            $current = Auth::guard('web')->user();
            if (! $current) {
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
            } elseif ((string) $current->getAuthIdentifier() !== (string) $user->id) {
                // Im Browser ist schon jemand anderes angemeldet: nicht stillschweigend wechseln.
                $user = null;
            }
        }

        return redirect()->to($cleanUrl)->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /** Code prüfen und verbrauchen; liefert die anzumeldende Person oder null. */
    private function consume(Request $request, string $code): ?User
    {
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->has() || ! WebSessionController::validCode($code)) {
            return null;
        }

        // RLS und globaler Scope: nur Codes der aufgerufenen Schule sind sichtbar.
        $record = ApiMobileWebSession::where('code_hash', DeviceSessions::hash($code))->first();
        if (! $record || $record->tenant_id !== $tenantContext->id() || $record->used_at !== null || $record->expires_at->isPast()) {
            return null;
        }
        // Einmalig: nur der erste (auch parallele) Aufruf setzt used_at.
        $claimed = ApiMobileWebSession::whereKey($record->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->update(['used_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        $verifier = (string) $request->header(WebSessionController::VERIFIER_HEADER, '');
        if (! WebSessionController::validVerifier($verifier) || ! hash_equals((string) $record->verifier_hash, hash('sha256', $verifier))) {
            return null;
        }

        $deviceSessionValid = ApiDeviceSession::whereKey($record->api_device_session_id)
            ->where('user_id', $record->user_id)
            ->whereNull('revoked_at')
            ->where('refresh_expires_at', '>', now())
            ->where('max_expires_at', '>', now())
            ->exists();
        $user = User::on('pgsql_admin')->whereKey($record->user_id)->where('status', 'active')->first();
        if (! $deviceSessionValid || ! $user || WebSessionController::privileged($user)) {
            return null;
        }
        $isActiveLearner = TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $record->tenant_id)
            ->where('user_id', $user->id)->where('role', 'learner')->where('status', 'active')->exists();

        return $isActiveLearner ? $user : null;
    }
}
