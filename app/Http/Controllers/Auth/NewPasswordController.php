<?php

namespace App\Http\Controllers\Auth;

use App\Api\DeviceSessions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        // Review-Runde 12: abgelaufene oder schon benutzte Links gleich beim
        // Öffnen erkennen -- bisher kam die Meldung erst nach dem Eintippen
        // des neuen Passworts (und sprach nur von „Zurücksetzen“).
        $linkInvalid = false;
        $email = $request->query('email');
        $token = (string) $request->route('token');
        if (is_string($email) && $email !== '') {
            $user = User::on('pgsql_admin')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
            $broker = $request->boolean('invite') && $user?->status === 'invited' ? 'invites' : null;
            $linkInvalid = ! $user || ! Password::broker($broker)->tokenExists($user, $token);
        }

        return view('auth.reset-password', ['request' => $request, 'linkInvalid' => $linkInvalid]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        // Einladungs-Links (AccountSetupLink) sind 7 Tage gültig: eigener Broker.
        // Einladungs-Broker nur für Konten, die noch nie ein Passwort gesetzt haben.
        $invited = $request->boolean('invite')
            && User::on('pgsql_admin')->whereRaw('lower(email) = ?', [mb_strtolower((string) $request->input('email'))])->value('status') === 'invited';
        $status = Password::broker($invited ? 'invites' : null)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            fn (User $user) => self::completeReset($user, (string) $request->password)
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        //
        // Sicherheitsreview S2: Password::reset() liefert bei unbekannter
        // E-Mail-Adresse Password::INVALID_USER ("passwords.user"), bei
        // bekannter Adresse mit falschem/abgelaufenem Token dagegen
        // Password::INVALID_TOKEN ("passwords.token") -- zwei Übersetzungen
        // mit unterschiedlichem Text (siehe lang/de/passwords.php). Ein
        // Angreifer, der hier mit einer geratenen E-Mail-Adresse und einem
        // beliebigen (ungültigen) Token postet, konnte allein an der Antwort
        // ablesen, ob diese Adresse auf der Plattform registriert ist.
        // Beide Fehlerfälle zeigen deshalb jetzt denselben Text, unabhängig
        // davon, welchen der beiden Status Laravel zurückgegeben hat.
        return $status == Password::PASSWORD_RESET
                    // Review-Runde 11: E-Mail-Adresse vorausgefüllt, Begrüßung für Neue.
                    ? redirect()->route('login')->withInput($request->only('email'))
                        ->with('status', $request->boolean('invite') ? 'Dein Passwort ist festgelegt. Du kannst dich jetzt anmelden.' : __($status))
                        ->with('first_login', $request->boolean('invite'))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __('passwords.token')]);
    }

    /**
     * Neues Passwort setzen und alles Bisherige beenden -- gemeinsam für den
     * Web-Ablauf und die Mobile-API (POST /api/v1/auth/password/reset).
     */
    public static function completeReset(User $user, string $password): void
    {
        // Review-Runde 12: ist der Reset der erste Nachweis der Mailbox,
        // stammt eine schon eingerichtete 2FA nicht sicher vom Besitzer
        // (jemand konnte die Adresse vor ihm registrieren) -- zurücksetzen.
        if ($user->email_verified_at === null && $user->two_factor_secret !== null) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        }
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
            // Bug-Review B18: ein über ParticipantController::invite()
            // neu angelegtes Konto bekommt den Status "invited" und
            // ein zufälliges Passwort -- genau dieser Reset-Link setzt
            // das erste eigene Passwort. Ohne diesen Übergang blieb
            // der Status für immer auf "invited" stehen, und
            // LoginRequest verlangt status=active -- das Konto konnte
            // sich also nie einloggen. Ein bereits gesperrtes
            // (suspended/closed) Konto bleibt bewusst unverändert.
            'status' => $user->status === 'invited' ? 'active' : $user->status,
            // Bug-Review NEU-7: ein erfolgreicher Passwort-Reset
            // bestätigt den Besitz der Mailbox genauso stark wie ein
            // eigener Verifizierungslink (beide beweisen sich
            // ausschließlich darüber, dass der tatsächliche Besitzer
            // einen an diese Adresse geschickten, signierten Token
            // zurückgibt) -- ohne das blieb email_verified_at trotz
            // bewiesenem Mailbox-Besitz null. app:create-superadmin
            // empfiehlt genau diesen Weg ("z. B. Passwort-Reset-Link")
            // als Nachweis für ein unbestätigtes Konto; ohne diese
            // Zeile hätte der Befehl danach trotzdem weiter abgelehnt.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // Beendet alle laufenden Sitzungen dieses Kontos (z. B. eines
        // Angreifers, der es zuvor übernommen hatte) -- ohne das würde
        // ein Passwort-Reset durch den rechtmäßigen Besitzer dessen
        // aktive Sessions nicht beenden (Sicherheitsreview H2).
        DB::table('sessions')->where('user_id', $user->id)->delete();
        // Offene Reset- und Einladungslinks verlieren ihre Gültigkeit.
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('account_setup_tokens')->where('email', $user->email)->delete();

        event(new PasswordReset($user));
        // Mobile-API: auch alle App-Gerätesitzungen beenden.
        DeviceSessions::revokeAllFor($user->id);
    }
}
