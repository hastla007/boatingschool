<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\ApiMobileWebSession;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anmeldung der eingebetteten Webansicht aus der App: ein Einmal-Code
 * (60 Sekunden, einmalig, nur als Hash gespeichert) an Konto,
 * Gerätesitzung und Bootsschule gebunden, eingelöst von
 * ConsumeMobileWebSession. Zusätzlich muss die WebView einen Verifier im
 * Header mitschicken -- die URL allein (Verlauf, Logs) genügt nicht.
 * Ziele nur im Lernbereich der Schule (kein Profil, keine Verwaltung).
 */
class WebSessionController extends Controller
{
    public const CODE_PARAM = 'bp_app_login';

    public const VERIFIER_HEADER = 'X-Boatingschool-Web-Verifier';

    public const TTL_SECONDS = 60;

    private const CODE_PATTERN = '/^bsw_[A-Za-z0-9_-]{43}$/';

    private const VERIFIER_PATTERN = '/^bsv_[A-Za-z0-9_-]{43}$/';

    /** Erlaubte Ziele (Pfad relativ zur Schuladresse). */
    private const ALLOWED_PAGES = ['/dashboard', '/courses', '/tipps-tricks', '/angebote', '/willkommensbox'];

    public function create(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! config('mobile_api.web_session_enabled', true)) {
            throw new ApiProblem(403, 'WEB_SESSION_DISABLED', 'Webansicht nicht verfügbar', 'Die Anmeldung in der Webansicht über die App ist abgeschaltet.');
        }

        $user = $request->user();
        if (self::privileged($user)) {
            throw new ApiProblem(403, 'WEB_SESSION_NOT_ALLOWED', 'Webansicht nicht erlaubt', 'Für Konten mit Verwaltungsrechten ist die Anmeldung in der Webansicht über die App nicht möglich.');
        }

        $limit = 'api-web-session:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 20)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Anmeldungen', 'Bitte später erneut versuchen.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($limit),
            ]);
        }
        RateLimiter::hit($limit, 900);

        $tenant = $tenantContext->tenant();
        $root = CanonicalUrl::root($tenant);
        $validated = $request->validate(['redirect' => ['bail', 'required', 'string', 'max:400']]);
        $path = self::normalizeTarget($validated['redirect'], $root);
        if ($path === null) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                'redirect' => ['Erlaubt sind nur Seiten im Lernbereich dieser Bootsschule.'],
            ]);
        }

        $apiSession = $request->attributes->get('api_session');

        $code = 'bsw_'.self::randomToken();
        $verifier = 'bsv_'.self::randomToken();
        $expires = now()->addSeconds(self::TTL_SECONDS);
        ApiMobileWebSession::where('user_id', $user->id)->where('expires_at', '<', now()->subDay())->delete();
        ApiMobileWebSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'api_device_session_id' => $apiSession->id,
            'code_hash' => hash('sha256', $code),
            'verifier_hash' => hash('sha256', $verifier),
            'redirect_path' => $path,
            'expires_at' => $expires,
            'created_at' => now(),
        ]);

        return response()->json(['data' => [
            'url' => $root.$path.(str_contains($path, '?') ? '&' : '?').self::CODE_PARAM.'='.$code,
            'redirect' => $root.$path,
            'expires_at' => $expires->toIso8601ZuluString(),
            'expires_in' => self::TTL_SECONDS,
            'verifier' => $verifier,
            'verifier_header' => self::VERIFIER_HEADER,
        ]], 201);
    }

    /**
     * Ziel auf einen Pfad im Lernbereich zurückführen: relativ zur
     * Schuladresse ("/courses/…") oder als absolute URL, die mit der
     * Schuladresse beginnt. Alles andere (fremde Hosts, "//", "\", "..",
     * Steuerzeichen, Fragmente) ergibt null.
     */
    public static function normalizeTarget(string $target, string $root): ?string
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 400 || preg_match('/[\x00-\x20\x7f\\\\#@]/', $target) === 1) {
            return null;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target) === 1) {
            if (strncasecmp($target, $root.'/', strlen($root) + 1) !== 0) {
                return null;
            }
            $target = substr($target, strlen($root));
        }
        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);
        if (preg_match('#^/[A-Za-z0-9/_.~-]*$#', $path) !== 1 || str_contains($path, '//')
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }
        $path = rtrim($path, '/') ?: '/';
        $allowed = in_array($path, self::ALLOWED_PAGES, true) || str_starts_with($path, '/courses/');
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

    /** Superadmin oder Team-Mitglied irgendeiner Schule: keine Web-Anmeldung über die App. */
    public static function privileged(User $user): bool
    {
        return (bool) $user->is_superadmin
            || TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('user_id', $user->id)
                ->where('role', '!=', 'learner')->exists();
    }

    public static function validCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    public static function validVerifier(string $verifier): bool
    {
        return preg_match(self::VERIFIER_PATTERN, $verifier) === 1;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
