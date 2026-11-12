<?php

namespace App\\Http\\Middleware;

use App\\Api\\DeviceSessions;
use App\\Http\\Controllers\\Api\\V1\\WebSessionController;
use App\\Models\\ApiDeviceSession;
use App\\Models\\ApiMobileWebSession;
use App\\Models\\User;
use Illuminate\\Auth\\AuthenticationException;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\DB;
use Symfony\\Component\\HttpFoundation\\Response;

class ConsumeMobileWebSession
{
    public function handle(Request $request, \\Closure $next): Response
    {
        $code = (string) $request->query(WebSessionController::CODE_PARAM, '');
        if ($code === '') {
            return $next($request);
        }

        $target = $request->getPathInfo();
        $query = $request->query();
        unset($query[WebSessionController::CODE_PARAM]);
        $cleanTarget = $target.($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $failure = fn () => redirect('/login?'.http_build_query(['redirect_to' => $cleanTarget]))
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);

        if (! WebSessionController::validCode($code)) {
            return $failure();
        }

        $hash = DeviceSessions::hash($code);
        $record = ApiMobileWebSession::where('code_hash', $hash)->first();
        if (! $record || $record->used_at !== null || $record->expires_at->isPast()) {
            return $failure();
        }

        $used = ApiMobileWebSession::whereKey($record->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->update(['used_at' => now()]);
        if ($used !== 1) {
            return $failure();
        }

        $verifier = (string) $request->header(WebSessionController::VERIFIER_HEADER, '');
        $session = ApiDeviceSession::whereKey($record->api_device_session_id)
            ->where('user_id', $record->user_id)
            ->whereNull('revoked_at')
            ->where('refresh_expires_at', '>', now())
            ->where('max_expires_at', '>', now())
            ->first();
        $user = User::on('pgsql_admin')->whereKey($record->user_id)->where('status', 'active')->first();
        $verifierValid = preg_match('/^bsv_[A-Za-z0-9_-]{43}$/', $verifier) === 1
            && hash_equals((string) $record->verifier_hash, hash('sha256', $verifier));
        $privileged = $user && ((bool) $user->is_superadmin
            || \\App\\Models\\TenantUser::on('pgsql_admin')->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'admin', 'instructor'])->exists());

        if (! $session || ! $user || ! $verifierValid || $privileged) {
            return $failure();
        }

        $current = Auth::guard('web')->user();
        if ($current && (string) $current->getAuthIdentifier() !== (string) $user->id) {
            return $failure();
        }
        if (! $current) {
            Auth::guard('web')->login($user, false);
            $request->session()->regenerate();
        }

        return redirect($request->getSchemeAndHttpHost().$record->redirect_path)
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
