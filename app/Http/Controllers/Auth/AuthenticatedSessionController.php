<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if ($request->user()->is_superadmin) {
            // Bug-Review (Niedrig): /login ist mandantenunabhängig und auf
            // JEDEM Host erreichbar (siehe ResolveTenant), /superadmin*
            // seit der NEU-3-Korrektur aber bewusst nur noch auf der
            // zentralen Domain. route(..., absolute: false) erzeugt einen
            // reinen Pfad ("/superadmin") -- der Browser löst den gegen
            // den AKTUELLEN Host auf; meldete sich ein Superadmin über
            // eine Bootsschul-Subdomain an, landete er dadurch auf einer
            // dort nicht mehr existierenden Route (404) statt im eigenen
            // Dashboard. Auf jedem anderen Host als der zentralen Domain
            // deshalb explizit per absoluter URL dorthin umleiten.
            if ($request->getHost() !== config('app.central_domain')) {
                return redirect()->to(rtrim(config('app.url'), '/').route('superadmin.dashboard', absolute: false));
            }

            return redirect()->intended(route('superadmin.dashboard', absolute: false));
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
}
