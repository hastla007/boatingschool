<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
    public function accept(Request $request, TenantInvitation $invitation): RedirectResponse
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
            abort(403, 'Bitte bestätige zuerst deine E-Mail-Adresse, bevor du Einladungen annimmst.');
        }

        TenantUser::firstOrCreate(
            ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
            ['role' => $invitation->role, 'status' => 'active']
        );

        $invitation->update(['accepted_at' => now()]);

        AuditLog::record($invitation->tenant_id, $user->id, 'invitation.accept', 'tenant_invitation', $invitation->id, null, [
            'user_id' => $user->id,
        ]);

        return redirect()->route('dashboard')->with('status', 'Einladung angenommen -- du bist jetzt Mitglied.');
    }
}
