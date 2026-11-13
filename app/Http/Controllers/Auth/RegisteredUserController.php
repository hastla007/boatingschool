<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\DuplicateRegistrationAttempt;
use App\Services\CouponRedemptionService;
use App\Support\BestEffortMail;
use App\Support\EmailInput;
use App\Support\RegistrationCouponNotice;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class RegisteredUserController extends Controller
{
    public function create(TenantContext $tenantContext): View
    {
        // Selbstregistrierung abgeschaltet: Hinweis statt Formular.
        return view('auth.register', [
            'registrationClosed' => ! self::allowsSelfRegistration($tenantContext->tenant()),
        ]);
    }

    /** Schalter „Selbstregistrierung erlauben“ im Branding der Schule (Standard an). */
    public static function allowsSelfRegistration(?Tenant $tenant): bool
    {
        return $tenant?->branding?->allowsSelfRegistration() ?? true;
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, TenantContext $tenantContext, CouponRedemptionService $redemption): RedirectResponse
    {
        // Selbstregistrierung von der Schule abgeschaltet: Konten entstehen nur per Einladung.
        abort_unless(self::allowsSelfRegistration($tenantContext->tenant()), 403, 'Bei dieser Bootsschule werden Konten nur per Einladung der Bootsschule angelegt.');

        // Kein "unique"-Regelverstoß mehr auf dem email-Feld (Sicherheitsreview
        // M3): Laravels Standardmeldung "wurde bereits vergeben" bestätigt
        // sonst platformweit, ob eine Adresse irgendwo existiert, auf jeder
        // beliebigen Bootsschul-Subdomain. Der Duplikat-Fall unten bekommt
        // stattdessen eine neutrale Meldung statt eines Feld-Fehlers.
        EmailInput::normalize($request, 'email');
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Optionaler Coupon-Code: ein ungültiger Code blockiert die
            // Registrierung nicht (Hinweis nach der Anmeldung, siehe unten).
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $tenant = $tenantContext->tenant();
        abort_if(! $tenant, 400, 'Registrierung erfordert eine gültige Bootsschul-Domain.');

        // Bug-Review M3 (Folgefehler): die neutrale Formulierung unten allein
        // hat das Orakel nicht geschlossen -- bis hierher führten beide
        // Zweige noch zu UNTERSCHIEDLICHEN Antworten (sofortiges Login +
        // Redirect ins Dashboard bei neuer Adresse, bloßer Zurück-Redirect
        // ohne Login beim Duplikat), was allein über diese abweichende
        // Antwortstruktur verrät, ob ein Konto existiert -- unabhängig vom
        // Text. Beide Zweige liefern deshalb jetzt denselben
        // Zurück-Redirect mit demselben Status-Text; ein neu angelegtes
        // Konto wird nicht mehr sofort eingeloggt, sondern muss sich zuerst
        // ganz normal über "Anmelden" einloggen -- der signierte
        // Verifizierungs-Link in der E-Mail (siehe unten) greift dann über
        // die bestehende "auth"-Middleware (routes/auth.php) wie gewohnt.
        $status = 'Fast fertig – falls die Adresse neu ist, haben wir dir eine Bestätigungsmail geschickt; bitte melde dich an, um fortzufahren. Falls sie bereits bei uns registriert ist, melde dich stattdessen einfach an oder setze dein Passwort zurück.';

        // Sicherheitsreview M7: app_user hat jetzt RLS -- diese
        // Duplikat-Erkennung muss aber bewusst plattformweit suchen (das ist
        // ihr ganzer Zweck), deshalb hier explizit über die RLS-freie
        // Owner-Verbindung.
        // Sicherheitsreview (Mail-Flut): je Empfängeradresse höchstens eine
        // Mail pro Stunde aus der Registrierung, egal von wie vielen IPs.
        // Beide Zweige zählen gegen dasselbe Limit, damit eine
        // übersprungene Mail kein Zeit-Orakel für bestehende Konten ist.
        $mailKey = 'registration-mail:'.sha1(strtolower($request->email));

        if (User::on('pgsql_admin')->whereRaw('lower(email) = ?', [mb_strtolower((string) $request->email)])->exists()) {
            // Bug-Review M3 (Folgefehler): ein verworfener Dummy-Hash gleicht
            // die Laufzeit an den Erfolgsfall an (der dort unten erzeugte
            // echte Hash ist der mit Abstand teuerste Schritt) -- sonst bliebe
            // selbst bei identischer Antwort noch ein Timing-Seitenkanal.
            Hash::make($request->password);

            // Bug-Review R4-F: der Neukonto-Zweig löst unten synchron einen
            // echten SMTP-Versand aus (Verifizierungsmail) -- ein Dummy-Hash
            // allein gleicht dessen Laufzeit nicht an, ein SMTP-Roundtrip
            // dominiert jede lokale Rechenzeit deutlich. Dieser Versand hat
            // zugleich einen echten Zweck (siehe DuplicateRegistrationAttempt).
            if (! RateLimiter::tooManyAttempts($mailKey, 1)) {
                RateLimiter::hit($mailKey, 3600);
                BestEffortMail::send(
                    fn () => Notification::route('mail', $request->email)->notify(new DuplicateRegistrationAttempt),
                    'Hinweismail Registrierungsversuch auf bestehendes Konto'
                );
            }

            // Ein mitgeschickter Code bleibt hier bewusst unangetastet (fremdes
            // Konto) -- die Antwort ist dieselbe wie bei einem neuen Konto.
            return back()->withInput($request->except('password', 'password_confirmation', 'coupon_code'))->with('status', $status);
        }

        $user = DB::transaction(function () use ($request, $tenant) {
            $user = User::create([
                'display_name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
                // E-Mail muss bestätigt werden, bevor "verified"-geschützte
                // Routen zugänglich sind (siehe Sicherheitsreview H2) -- die
                // Registered-Event-Listener (AppServiceProvider) verschickt
                // dafür automatisch die Verifizierungs-Mail.
                // Bug-Review (Superadmin-Nutzerseite zeigt leere
                // Namensfelder): first_name/last_name blieben hier bisher
                // unbesetzt, siehe User::splitDisplayName().
                ...User::splitDisplayName($request->name),
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'learner',
                'status' => 'active',
            ]);

            return $user;
        });

        // Bug-Review SMTP: der Registered-Listener (AppServiceProvider)
        // verschickt die Verifizierungs-Mail synchron -- ein SMTP-Fehler
        // ergab HTTP 500, obwohl das Konto (siehe oben) schon erfolgreich
        // angelegt war. Analog zu den bereits auf BestEffortMail
        // umgestellten Stellen (Bug-Review B31) wird ein Fehler nur noch
        // geloggt, die Registrierung selbst schlägt dadurch nicht mehr fehl.
        RateLimiter::hit($mailKey, 3600);
        BestEffortMail::send(
            fn () => event(new Registered($user)),
            "Verifizierungsmail Registrierung {$user->id}"
        );

        // Coupon-Code aus dem Registrierungsformular direkt nach dem Anlegen
        // einlösen. Das Ergebnis (auch „ungültig“) erscheint erst nach der
        // Anmeldung (RegistrationCouponNotice) -- die Antwort hier bleibt für
        // neue und bestehende Adressen gleich (Bug-Review M3).
        $code = trim((string) $request->input('coupon_code'));
        if ($code !== '') {
            RegistrationCouponNotice::remember($user, $this->redeemRegistrationCoupon($code, $tenant, $user, $redemption));
        }

        return back()->withInput($request->except('password', 'password_confirmation', 'coupon_code'))->with('status', $status);
    }

    /**
     * Dieselben Prüfungen wie CouponRedeemController::redeem(), aber ohne
     * Formularfehler: das Konto existiert bereits, ein Fehler beim Code darf
     * die Registrierung nicht mehr scheitern lassen.
     *
     * @return array{type: 'success'|'error', message: string}
     */
    private function redeemRegistrationCoupon(string $code, Tenant $tenant, User $user, CouponRedemptionService $redemption): array
    {
        $coupon = Coupon::lookup($code);

        if (! $coupon || $coupon->isRedeemed()) {
            return ['type' => 'error', 'message' => "Der Coupon-Code aus deiner Registrierung ({$code}) ist ungültig oder wurde bereits eingelöst. Du kannst einen gültigen Code jederzeit unter „Coupon-Code einlösen“ eingeben."];
        }
        if (! $coupon->isUsable()) {
            return ['type' => 'error', 'message' => "Der Coupon-Code aus deiner Registrierung ({$code}) ist nicht mehr gültig. Bitte wende dich an deine Bootsschule."];
        }

        try {
            $result = $redemption->redeem($coupon, $tenant, $user);
        } catch (Throwable $e) {
            // Auch Datenbankfehler nur melden, das Konto bleibt bestehen.
            if (! $e instanceof RuntimeException || $e instanceof QueryException) {
                report($e);
            }

            return ['type' => 'error', 'message' => "Der Coupon-Code aus deiner Registrierung ({$code}) konnte nicht eingelöst werden. Bitte versuche es unter „Coupon-Code einlösen“ erneut."];
        }

        $name = $result['type'] === 'product' ? $result['product']->name : $result['course']->name;

        return ['type' => 'success', 'message' => "Dein Coupon-Code aus der Registrierung wurde eingelöst – „{$name}“ ist freigeschaltet."];
    }
}
