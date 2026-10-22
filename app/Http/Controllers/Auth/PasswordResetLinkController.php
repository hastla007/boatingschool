<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\BestEffortMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Bug-Review SMTP: lief bisher synchron und ungeschützt -- ein
        // SMTP-Fehler ergab HTTP 500, obwohl es hier gar keine eigene
        // Schreiboperation gibt, die dadurch gefährdet wäre (nur der
        // Versand selbst). Analog zu den bereits auf BestEffortMail
        // umgestellten Stellen (Bug-Review B31) wird ein Fehler nur noch
        // geloggt.
        BestEffortMail::send(
            fn () => Password::sendResetLink($request->only('email')),
            'Passwort-Reset-Link für '.$request->input('email')
        );

        // Sicherheitsreview M3: unabhängig davon, ob die Adresse tatsächlich
        // registriert ist, bekommt der Request immer dieselbe Antwort --
        // Laravels Standardverhalten hätte hier sonst einen Feld-Fehler samt
        // zurückgegebenem Input gezeigt, falls die Adresse NICHT existiert,
        // und einen Erfolgs-Flash ohne Feld-Fehler, falls doch. Selbst mit
        // bewusst neutral formuliertem Text verrät allein diese
        // UNTERSCHIEDLICHE ANTWORTSTRUKTUR (Fehler+Input vs. reiner Erfolg)
        // schon, ob eine Adresse existiert -- jetzt immer derselbe
        // Erfolgs-Flash, egal was Password::sendResetLink() zurückgibt.
        return back()->with('status', __('passwords.sent'));
    }
}
