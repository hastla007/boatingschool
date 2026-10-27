<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if ($request->user()->is_superadmin) {
            // Bug-Review (Niedrig, Folgefehler): /login ist
            // mandantenunabhängig und auf JEDEM Host erreichbar (siehe
            // ResolveTenant), /superadmin* seit der NEU-3-Korrektur aber
            // bewusst nur noch auf der zentralen Domain. Ein per
            // absoluter URL dorthin umgeleiteter Superadmin landete dort
            // trotzdem nicht eingeloggt: das Session-Cookie bleibt (seit
            // NEU-1, bewusst) an genau den Host gebunden, der es gesetzt
            // hat -- auf der Subdomain gesetzt, wird es auf der zentralen
            // Domain nie mitgeschickt. Eine zweite, eigene Anmeldung dort
            // ist deshalb unvermeidbar; der Umweg über /superadmin (das
            // ohne gültige Session ohnehin nur zurück zu /login
            // umgeleitet hätte) entfällt dafür -- stattdessen direkt
            // dorthin, mit einer erklärenden Meldung statt eines
            // verwirrenden Doppel-Redirects.
            if ($request->getHost() !== config('app.central_domain')) {
                return redirect()
                    ->to($this->centralDomainUrl($request, route('login', absolute: false)))
                    ->with('status', 'Der Superadmin-Bereich ist nur über die zentrale Domain erreichbar -- bitte melde dich hier noch einmal an.');
            }

            return redirect()->intended(route('superadmin.dashboard', absolute: false));
        }

        // Bug-Review Oberfläche U3: ein Login leitete bisher IMMER auf
        // /dashboard (das Lernenden-Dashboard) weiter -- ein Owner/Admin/
        // Instructor hat dort aber meist keine eigene Freischaltung und
        // landete so auf einer verwirrenden Seite mit "Für dich ist noch
        // kein Kurs freigeschaltet", statt im eigentlich für ihre Rolle
        // gedachten Bootsschul-Admin-Bereich. 'owner', 'admin', 'instructor'
        // entspricht exakt den Rollen, die routes/web.php für den
        // /admin-Routenblock zulässt (tenant.role:owner,admin,instructor).
        //
        // /login ist als "tenant-agnostisch" eingestuft (siehe
        // ResolveTenant::isTenantAgnosticRoute(), Sicherheitsreview R3-1) --
        // TenantContext bleibt dort bewusst ungesetzt (Nil-UUID), damit die
        // Login-Abfrage selbst app_user unabhängig vom Tenant sehen kann.
        // ResolveTenant hinterlegt den über Subdomain/Custom-Domain/Dev-
        // Override aufgelösten Tenant trotzdem fürs Branding als
        // "currentTenant"-Instanz -- genau den nutzt dieser Block hier,
        // statt TenantContext. roleForTenant() fragt tenant_user ab, das
        // ebenfalls per RLS an app.current_tenant_id hängt; der Tenant-
        // Kontext wird deshalb NUR für diese eine Abfrage (NACH der
        // bereits abgeschlossenen Authentifizierung, R3-1 betrifft also
        // nicht mehr diesen Nutzer) kurz gesetzt und sofort wieder
        // geleert.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $role = null;
        if ($tenant) {
            $tenantContext->set($tenant);
            $role = $request->user()->roleForTenant($tenant);
            $tenantContext->clear();
        }

        if ($tenant && in_array($role, ['owner', 'admin', 'instructor'], true)) {
            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Bug-Review (Niedrig, Folgefehler): die erste Fassung baute die
     * absolute URL aus config('app.url') -- das setzt implizit voraus,
     * dass APP_URL zur zentralen Domain passt (CENTRAL_DOMAIN ist davon
     * unabhängig konfigurierbar, siehe ResolveTenant). Weichen beide
     * Werte ab, hätte der Redirect auf dem FALSCHEN Host gelandet. Host
     * und Schema kommen deshalb jetzt direkt aus CENTRAL_DOMAIN bzw. dem
     * aktuellen Request, nicht aus APP_URL.
     */
    private function centralDomainUrl(Request $request, string $path): string
    {
        return $request->getScheme().'://'.config('app.central_domain').$path;
    }
}
