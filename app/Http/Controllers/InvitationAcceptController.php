<?php

namespace App\Http\Controllers;

use App\Models\TenantInvitation;
use App\Services\InvitationAcceptance;
use App\Support\AgeConfirmation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gegenstück zu ParticipantController::invite() für eine bereits
 * existierende E-Mail-Adresse (siehe TenantInvitation, Sicherheitsreview
 * H2): die Mitgliedschaft entsteht erst hier, und nur, wenn der gerade
 * eingeloggte Nutzer tatsächlich die eingeladene (bestätigte) Adresse hat
 * -- ein Angreifer, der die Adresse nur vorab registriert hat, kann die
 * Einladung so nicht für sich selbst annehmen.
 */
class InvitationAcceptController extends Controller
{
    public function accept(Request $request, TenantInvitation $invitation, InvitationAcceptance $acceptance): Response
    {
        if (! hash_equals(sha1($invitation->email), (string) $request->route('hash'))) {
            abort(403);
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('login')->with('status', 'Diese Einladung wurde bereits angenommen.');
        }

        $user = $request->user();

        if (! $user) {
            return redirect()->route('login')->with('status', 'Bitte melde dich mit der eingeladenen E-Mail-Adresse an, um die Einladung anzunehmen.');
        }

        if (strcasecmp($user->email, $invitation->email) !== 0) {
            abort(403, 'Diese Einladung gilt für eine andere E-Mail-Adresse. Bitte melde dich mit der eingeladenen Adresse an.');
        }

        if (! $user->hasVerifiedEmail()) {
            // Zur Bestätigungsseite (mit „erneut senden“); nach der Bestätigung
            // führt EnsureTenantMembership automatisch zur offenen Einladung.
            return redirect()->route('verification.notice')
                ->with('status', 'Bitte bestätige zuerst deine E-Mail-Adresse, danach wird die Einladung automatisch angenommen.');
        }

        // Altersbestätigung einmalig nachholen (Konten von vor der Pflicht-
        // Checkbox bei der Registrierung): erst fragen, dann annehmen.
        if ($user->age_confirmed_at === null) {
            if (! $request->isMethod('POST')) {
                return response()->view('auth.confirm-age', [
                    'action' => $request->fullUrl(),
                    'tenantName' => $invitation->tenant?->name,
                ]);
            }
            $request->validate(['age_confirmed' => ['accepted']], ['age_confirmed.accepted' => AgeConfirmation::MESSAGE]);
            $user->forceFill(['age_confirmed_at' => now()])->save();
        }

        $acceptance->accept($invitation, $user);

        return redirect()->route('dashboard')->with('status', 'Einladung angenommen – du bist jetzt Mitglied.');
    }
}
