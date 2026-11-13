<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\DeviceSessions;
use App\Api\Present;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NewLoginDeviceNotifier;
use App\Support\BestEffortMail;
use App\Support\TwoFactorCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules;

/**
 * Anmeldung der Lernenden-App mit dem bestehenden Web-Konto: Gerätesitzung
 * mit Access-/Refresh-Token, 2FA-Schritt, Passwort vergessen/zurücksetzen,
 * Bestätigungsmail. Sperren und Grenzen wie beim Web-Login.
 */
class AuthController extends Controller
{
    /** Fehlversuche je Konto in 15 Minuten (gemeinsam mit dem Web-Login). */
    private const ACCOUNT_ATTEMPTS = 20;

    public function __construct(private DeviceSessions $sessions) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'password' => ['bail', 'required', 'string', 'max:1000'],
            'device_name' => ['bail', 'required', 'string', 'max:100'],
            'device_platform' => ['nullable', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $ipKey = 'api-login:'.Str::transliterate($email).'|'.$request->ip();
        $accountKey = 'login-account:'.Str::transliterate($email);
        foreach ([[$ipKey, 5], [$accountKey, self::ACCOUNT_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);
                throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Anmeldeversuche', 'Bitte später erneut versuchen.', [], ['Retry-After' => (string) $seconds]);
            }
        }

        // Einheitliche Mindestdauer: an der Antwortzeit ist nicht ablesbar,
        // ob ein Konto existiert (wie beim Web-Login).
        $user = (new Timebox)->call(function (Timebox $timebox) use ($email, $validated) {
            $user = User::on('pgsql_admin')->whereRaw('lower(email) = ?', [$email])->first();
            if ($user && $user->status === 'active' && Hash::check($validated['password'], (string) $user->password)) {
                return $user;
            }

            return null;
        }, (int) config('auth.timebox_duration', 200000));

        if (! $user) {
            RateLimiter::hit($ipKey);
            RateLimiter::hit($accountKey, 900);
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Anmeldung fehlgeschlagen', 'E-Mail-Adresse oder Passwort stimmen nicht.');
        }
        RateLimiter::clear($ipKey);
        RateLimiter::clear($accountKey);

        $device = ['name' => $validated['device_name'], 'platform' => $validated['device_platform'] ?? null, 'app_version' => $validated['app_version'] ?? null];

        if ($user->hasTwoFactor()) {
            // Zweiter Schritt: Code zur Abfrage-Kennung nachreichen.
            $challenge = Str::random(64);
            $minutes = config('mobile_api.two_factor_challenge_minutes');
            Cache::put('api-2fa:'.hash('sha256', $challenge), [
                'user_id' => $user->id,
                'pw' => hash('sha256', (string) $user->password),
                'device' => $device,
            ], now()->addMinutes($minutes));

            return response()->json(['data' => [
                'two_factor_required' => true,
                'challenge_token' => $challenge,
                'challenge_expires_at' => Present::time(now()->addMinutes($minutes)),
            ]]);
        }

        app(NewLoginDeviceNotifier::class)->handle($user, $request, $device['platform'] ?? '');

        return $this->issue($user, $device);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['bail', 'required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:40'],
        ]);
        $cacheKey = 'api-2fa:'.hash('sha256', $validated['challenge_token']);
        $pending = Cache::get($cacheKey);
        $user = $pending ? User::on('pgsql_admin')->find($pending['user_id']) : null;

        // Inzwischen gesperrt, Passwort geändert oder 2FA abgeschaltet: neu anmelden.
        if (! $user || $user->status !== 'active' || ! $user->hasTwoFactor()
            || ! hash_equals($pending['pw'], hash('sha256', (string) $user->password))) {
            Cache::forget($cacheKey);
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Anmeldung abgelaufen', 'Bitte erneut anmelden.');
        }

        $limit = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Versuche', 'Bitte später erneut versuchen.', [], ['Retry-After' => (string) RateLimiter::availableIn($limit)]);
        }
        RateLimiter::hit($limit, 300);

        if (! TwoFactorCheck::verify($user, (string) ($validated['code'] ?? ''), (string) ($validated['recovery_code'] ?? ''))) {
            throw new ApiProblem(422, 'TWO_FACTOR_INVALID', 'Code ungültig', 'Der Code stimmt nicht.', ['code' => ['Der Code stimmt nicht.']]);
        }

        RateLimiter::clear($limit);
        Cache::forget($cacheKey);
        app(NewLoginDeviceNotifier::class)->handle($user, $request, $pending['device']['platform'] ?? '');

        return $this->issue($user, $pending['device']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['bail', 'required', 'string', 'min:20', 'max:200']]);
        $issued = $this->sessions->refresh($validated['refresh_token']);
        if (! $issued) {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Sitzung abgelaufen', 'Bitte erneut anmelden.');
        }

        return response()->json(['data' => DeviceSessions::tokenPayload($issued)]);
    }

    public function logout(Request $request): Response
    {
        $this->sessions->revoke($request->attributes->get('api_session'));

        return response()->noContent();
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['bail', 'required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($validated['email']));

        // Der Link in der Mail führt zur Schule, wenn die App eine gewählt hat.
        $slug = $request->header('X-Tenant-Slug');
        if (is_string($slug) && ($tenant = Tenant::on('pgsql_admin')->where('slug', $slug)->first())) {
            app()->instance('currentTenant', $tenant);
        }

        // Versand nach der Antwort: Antwortzeit und Inhalt verraten nicht,
        // ob es ein Konto gibt.
        \Illuminate\Support\defer(fn () => BestEffortMail::send(
            fn () => Password::sendResetLink(['email' => User::on('pgsql_admin')->whereRaw('lower(email) = ?', [$email])->value('email') ?? $email]),
            'Passwort-Reset-Link (App)'
        ));

        return response()->json(['data' => ['status' => 'accepted']], 202);
    }

    public function resetPassword(Request $request): Response
    {
        $validated = $request->validate([
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'token' => ['bail', 'required', 'string', 'max:200'],
            'password' => ['bail', 'required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);
        $email = User::on('pgsql_admin')->whereRaw('lower(email) = ?', [Str::lower(trim($validated['email']))])->value('email') ?? $validated['email'];

        // Einladungslinks (noch nie angemeldet) nutzen den eigenen Broker.
        $invited = $request->boolean('invite') && User::on('pgsql_admin')->where('email', $email)->value('status') === 'invited';
        $status = Password::broker($invited ? 'invites' : null)->reset(
            ['email' => $email, 'token' => $validated['token'], 'password' => $validated['password'], 'password_confirmation' => $request->input('password_confirmation')],
            fn (User $user) => NewPasswordController::completeReset($user, $validated['password'])
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiProblem(422, 'PASSWORD_RESET_INVALID_OR_EXPIRED', 'Link ungültig oder abgelaufen', 'Bitte fordere einen neuen Link an.', ['token' => ['Der Link ist abgelaufen oder wurde schon benutzt.']]);
        }

        return response()->noContent();
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            BestEffortMail::send(fn () => $user->sendEmailVerificationNotification(), 'Bestätigungsmail (App) '.$user->id);
        }

        return response()->json(['data' => ['status' => $user->hasVerifiedEmail() ? 'already_verified' : 'sent']], 202);
    }

    /** @param  array{name: string, platform: ?string, app_version: ?string}  $device */
    private function issue(User $user, array $device): JsonResponse
    {
        $issued = $this->sessions->issue($user, $device['name'], $device['platform'], $device['app_version']);

        return response()->json(['data' => DeviceSessions::tokenPayload($issued) + [
            'user' => Present::user($user),
            'memberships' => Present::memberships($user),
        ]]);
    }
}
