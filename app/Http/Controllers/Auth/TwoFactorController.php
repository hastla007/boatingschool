<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Zwei-Faktor-Anmeldung (TOTP): einrichten, bestätigen, abschalten und die
 * Abfrage des Codes nach dem Passwort. Freiwillig für alle Konten,
 * empfohlen für Superadmin und Schul-Admins.
 */
class TwoFactorController extends Controller
{
    private const RECOVERY_CODES = 8;

    // --- Einstellungen (angemeldet) -------------------------------------

    public function show(Request $request): View
    {
        $user = $request->user();
        $pending = ! $user->hasTwoFactor() && $user->two_factor_secret;

        return view('auth.two-factor', [
            'enabled' => $user->hasTwoFactor(),
            'pendingSecret' => $pending ? $user->two_factor_secret : null,
            'qrSvg' => $pending ? $this->qrSvg($user) : null,
            'recoveryCodes' => session('two_factor.recovery_codes'),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409);
        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return redirect()->route('two-factor.show');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);
        abort_if(! $user->two_factor_secret || $user->hasTwoFactor(), 409);

        if (Totp::verify($user->two_factor_secret, $request->input('code')) === null) {
            return back()->withErrors(['code' => 'Der Code stimmt nicht. Bitte den aktuellen Code aus der App eingeben.']);
        }

        $codes = collect(range(1, self::RECOVERY_CODES))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();
        $this->audit($user, 'two_factor.enable');

        // Die Codes nur dieses eine Mal im Klartext zeigen.
        return redirect()->route('two-factor.show')->with('two_factor.recovery_codes', $codes)
            ->with('status', 'Zwei-Faktor-Anmeldung ist aktiv.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit($user, 'two_factor.disable');

        return redirect()->route('two-factor.show')->with('status', 'Zwei-Faktor-Anmeldung ist ausgeschaltet.');
    }

    // --- Abfrage nach dem Passwort (noch nicht angemeldet) -------------

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request, TenantContext $tenantContext, AuthenticatedSessionController $sessions): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Bitte erneut anmelden.']);
        }

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($key).' Sekunden erneut versuchen.']);
        }

        $code = trim((string) $request->input('code', ''));
        $recovery = trim((string) $request->input('recovery_code', ''));
        $ok = false;

        if ($code !== '') {
            // Derselbe Code gilt nur einmal (Schritt merken).
            $lastStep = cache()->get('two-factor-step:'.$user->id);
            $step = Totp::verify($user->two_factor_secret, $code, null, $lastStep);
            if ($step !== null) {
                cache()->put('two-factor-step:'.$user->id, $step, now()->addMinutes(5));
                $ok = true;
            }
        } elseif ($recovery !== '') {
            $hashes = $user->two_factor_recovery_codes ?? [];
            foreach ($hashes as $i => $hash) {
                if (Hash::check(Str::upper($recovery), $hash)) {
                    unset($hashes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    $ok = true;
                    break;
                }
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 300);

            return back()->withErrors(['code' => 'Der Code stimmt nicht.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) ($request->session()->get('two_factor.login.remember') ?? false);
        $request->session()->forget('two_factor.login');
        Auth::guard('web')->login($user, $remember);

        return $sessions->completeLogin($request, $tenantContext);
    }

    /** Wer gerade das Passwort richtig eingegeben hat (höchstens 10 Minuten). */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor.login');
        if (! $pending || now()->timestamp - ($pending['at'] ?? 0) > 600) {
            return null;
        }
        $user = User::on('pgsql_admin')->find($pending['id']);

        return $user && $user->hasTwoFactor() ? $user : null;
    }

    /**
     * Protokoll über die Owner-Verbindung: die Seiten laufen ohne
     * Mandanten-Kontext, die RLS-Policy von audit_log ließe einen Eintrag
     * ohne Bootsschule auf der App-Verbindung nicht zu.
     */
    private function audit(User $user, string $action): void
    {
        $original = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        try {
            AuditLog::record(null, $user->id, $action, 'app_user', $user->id, null, null);
        } finally {
            config(['database.default' => $original]);
        }
    }

    private function qrSvg(User $user): string
    {
        $uri = Totp::uri(PlatformSetting::siteName(), $user->email, $user->two_factor_secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }
}
