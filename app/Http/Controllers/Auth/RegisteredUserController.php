<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        // Kein "unique"-Regelverstoß mehr auf dem email-Feld (Sicherheitsreview
        // M3): Laravels Standardmeldung "wurde bereits vergeben" bestätigt
        // sonst platformweit, ob eine Adresse irgendwo existiert, auf jeder
        // beliebigen Bootsschul-Subdomain. Der Duplikat-Fall unten bekommt
        // stattdessen eine neutrale Meldung statt eines Feld-Fehlers.
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
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
        $status = 'Fast fertig -- falls die Adresse neu ist, haben wir dir eine Bestätigungsmail geschickt; bitte melde dich an, um fortzufahren. Falls sie bereits bei uns registriert ist, melde dich stattdessen einfach an oder setze dein Passwort zurück.';

        // Sicherheitsreview M7: app_user hat jetzt RLS -- diese
        // Duplikat-Erkennung muss aber bewusst plattformweit suchen (das ist
        // ihr ganzer Zweck), deshalb hier explizit über die RLS-freie
        // Owner-Verbindung.
        if (User::on('pgsql_admin')->where('email', $request->email)->exists()) {
            // Bug-Review M3 (Folgefehler): ein verworfener Dummy-Hash gleicht
            // die Laufzeit an den Erfolgsfall an (der dort unten erzeugte
            // echte Hash ist der mit Abstand teuerste Schritt) -- sonst bliebe
            // selbst bei identischer Antwort noch ein Timing-Seitenkanal.
            Hash::make($request->password);

            return back()->withInput($request->except('password', 'password_confirmation'))->with('status', $status);
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
        BestEffortMail::send(
            fn () => event(new Registered($user)),
            "Verifizierungsmail Registrierung {$user->id}"
        );

        return back()->withInput($request->except('password', 'password_confirmation'))->with('status', $status);
    }
}
