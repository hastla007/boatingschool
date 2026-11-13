<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CouponRedemptionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
            // Zur Bestätigungsseite (mit „erneut senden“); nach der Bestätigung
            // führt EnsureTenantMembership automatisch zur offenen Einladung.
            return redirect()->route('verification.notice')
                ->with('status', 'Bitte bestätige zuerst deine E-Mail-Adresse, danach wird die Einladung automatisch angenommen.');
        }

        TenantUser::firstOrCreate(
            ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
            ['role' => $invitation->role, 'status' => 'active']
        );

        $invitation->update(['accepted_at' => now()]);

        AuditLog::record($invitation->tenant_id, $user->id, 'invitation.accept', 'tenant_invitation', $invitation->id, null, [
            'user_id' => $user->id,
        ]);

        $this->redeemReservedCoupon($invitation, $user);

        return redirect()->route('dashboard')->with('status', 'Einladung angenommen – du bist jetzt Mitglied.');
    }

    /**
     * Ein beim Einladen angegebener Code (ParticipantController::invite)
     * wird bei einem bestehenden Konto erst hier eingelöst -- vorher war die
     * Person kein Mitglied. Ist der Code inzwischen verbraucht, gesperrt
     * oder abgelaufen, bleibt es still bei der Mitgliedschaft (die Schule
     * kann den Kurs danach wie gewohnt freischalten).
     */
    private function redeemReservedCoupon(TenantInvitation $invitation, User $user): void
    {
        if (! $invitation->coupon_code || $invitation->role !== 'learner') {
            return;
        }

        $coupon = Coupon::where('code', $invitation->coupon_code)->whereNull('redeemed_at')->first();
        if (! $coupon || ! $coupon->isUsable() || ($coupon->tenant_id !== null && $coupon->tenant_id !== $invitation->tenant_id)) {
            return;
        }

        try {
            app(CouponRedemptionService::class)->redeem($coupon, $invitation->tenant, $user, $invitation->invitedBy);
        } catch (RuntimeException $e) {
            throw_if($e instanceof QueryException, $e);
            Log::info('Vorgemerkter Einladungs-Code nicht eingelöst', ['invitation' => $invitation->id, 'grund' => $e->getMessage()]);
        }
    }
}
