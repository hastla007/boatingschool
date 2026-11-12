<?php

namespace App\\Http\\Controllers\\Api\\V1;

use App\\Api\\ApiProblem;
use App\\Http\\Controllers\\Controller;
use App\\Models\\ApiMobileWebSession;
use App\\Models\\TenantUser;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\RateLimiter;
use Illuminate\\Support\\Str;

class WebSessionController extends Controller
{
    public const CODE_PARAM = 'bp_app_login';
    public const VERIFIER_HEADER = 'X-Boatingschool-Web-Verifier';
    private const TTL_SECONDS = 60;
    private const CODE_PATTERN = '/^bsw_[A-Za-z0-9_-]{43}$/';

    public function create(Request $request): JsonResponse
    {
        if (! config('mobile_api.web_session_enabled', true)) {
            throw new ApiProblem(403, 'WEB_SESSION_DISABLED', 'Webansicht nicht verfügbar', 'Die Anmeldung in der Webansicht ist bei dieser Bootsschule abgeschaltet.');
        }

        $user = $request->user();
        if ($this->privileged($user)) {
            throw new ApiProblem(403, 'WEB_SESSION_NOT_ALLOWED', 'Webansicht nicht erlaubt', 'Für Konten mit Verwaltungsrechten ist die Anmeldung in der Webansicht über die App nicht möglich.');
        }

        $limit = 'api-web-session:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 20)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Anmeldungen', 'Bitte später erneut versuchen.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($limit),
            ]);
        }
        RateLimiter::hit($limit, 900);

        $validated = $request->validate(['redirect' => ['bail', 'required', 'string', 'max:400']]);
        $path = $this->normalizeTarget($validated['redirect'], $request);
        if ($path === null) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', 'Erlaubt sind nur Seiten im Lernbereich dieser Website.');
        }

        ApiMobileWebSession::where('created_at', '<', now()->subDay())->delete();\n\n        $apiSession = $request->attributes->get('api_session');
        if (! $apiSession || $apiSession->user_id !== $user->id || $apiSession->revoked_at
            || $apiSession->refresh_expires_at->isPast() || $apiSession->max_expires_at->isPast()) {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Sitzung abgelaufen', 'Bitte erneut anmelden.');
        }

        $code = 'bpw_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = 'bpv_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addSeconds(self::TTL_SECONDS);
        ApiMobileWebSession::create([
            'user_id' => $user->id,
            'api_device_session_id' => $apiSession->id,
            'code_hash' => hash('sha256', $code),
            'verifier_hash' => hash('sha256', $verifier),
            'redirect_path' => $path,
            'expires_at' => $expires,
            'created_at' => now(),
        ]);

        $origin = $request->getSchemeAndHttpHost();
        $url = $origin.$path.(str_contains($path, '?') ? '&' : '?').self::CODE_PARAM.'='.$code;

        return response()->json(['data' => [
            'url' => $url,
            'expires_at' => $expires->toIso8601ZuluString(),
            'expires_in' => self::TTL_SECONDS,
            'redirect' => $origin.$path,
            'verifier' => $verifier,
            'verifier_header' => WebSessionController::VERIFIER_HEADER,
        ]], 201);
    }

    private function normalizeTarget(string $target, Request $request): ?string
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 400 || preg_match('/[\\x00-\\x20\\x7f\\\\#]/', $target) === 1) {
            return null;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target) === 1) {
            $parts = parse_url($target);
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                || strtolower((string) ($parts['scheme'] ?? '')) !== strtolower($request->getScheme())
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower($request->getHost())
                || ($parts['port'] ?? null) !== parse_url($request->getSchemeAndHttpHost(), PHP_URL_PORT)) {
                return null;
            }
            $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }
        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);
        if (preg_match('#^/[A-Za-z0-9/_.~-]*$#', $path) !== 1
            || str_contains($path, '//') || preg_match('#(^|/)\\.\\.?(/|$)#', $path) === 1) {
            return null;
        }
        $allowed = str_starts_with($path.'/', '/courses/')
            || preg_match('#^/[A-Za-z0-9-]+/courses/#', $path) === 1
            || in_array($path, ['/dashboard', '/tipps-tricks', '/angebote', '/willkommensbox'], true)
            || preg_match('#^/[A-Za-z0-9-]+/(dashboard|tipps-tricks|angebote|willkommensbox)$#', $path) === 1;
        if (! $allowed) {
            return null;
        }

        if ($query !== null && $query !== '') {
            if (preg_match('/^[A-Za-z0-9_.~%=&+-]*$/', $query) !== 1) {
                return null;
            }
            parse_str($query, $params);
            unset($params[self::CODE_PARAM]);
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $path.($query !== null && $query !== '' ? '?'.$query : '');
    }

    private function privileged($user): bool
    {
        return (bool) ($user->is_superadmin ?? false)
            || TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'admin', 'instructor'])->exists();
    }

    public static function validCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }
}
