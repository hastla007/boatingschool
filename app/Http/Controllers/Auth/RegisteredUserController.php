<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Sicherheitsreview M7: app_user hat jetzt RLS -- diese
        // Duplikat-Erkennung muss aber bewusst plattformweit suchen (das ist
        // ihr ganzer Zweck), deshalb hier explizit über die RLS-freie
        // Owner-Verbindung.
        if (User::on('pgsql_admin')->where('email', $request->email)->exists()) {
            return back()->withInput($request->except('password', 'password_confirmation'))->with(
                'status',
                'Falls diese E-Mail-Adresse bereits bei uns registriert ist, melde dich stattdessen an oder setze dein Passwort zurück. Andernfalls prüfe bitte die Schreibweise und versuche es erneut.'
            );
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

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
