<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Eine Einladung annehmen -- gemeinsam für beide Wege, damit eine Schule
 * nicht erkennt, ob es zur Adresse schon ein Konto gab:
 * - Bestandskonto: über den Link „Einladung bestätigen“
 *   (InvitationAcceptController),
 * - neues Konto: beim Festlegen des ersten Passworts
 *   (NewPasswordController::completeReset).
 * In beiden Fällen wird ein beim Einladen angegebener Code erst hier
 * eingelöst; bis dahin ist er für die Einladung reserviert.
 */
class InvitationAcceptance
{
    /** Wie lange eine offene Einladung (und damit eine Code-Reservierung) gilt. */
    public const VALID_DAYS = 7;

    public function accept(TenantInvitation $invitation, User $user): void
    {
        $membership = TenantUser::firstOrCreate(
            ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
            ['role' => $invitation->role, 'status' => 'active']
        );
        // Eine Team-Einladung an jemanden, der schon Teilnehmer ist, hebt die
        // Rolle an (nie herab) -- die Schule hat sie ausdrücklich ins Team
        // eingeladen, die Person hat angenommen.
        if ($membership->role === 'learner' && in_array($invitation->role, ['instructor', 'admin'], true)) {
            $membership->update(['role' => $invitation->role]);
        }

        $invitation->update(['accepted_at' => now()]);

        AuditLog::record($invitation->tenant_id, $user->id, 'invitation.accept', 'tenant_invitation', $invitation->id, null, [
            'user_id' => $user->id,
        ]);

        $this->redeemReservedCoupon($invitation, $user);
    }

    /**
     * Ein von der Schule neu angelegtes Konto hat sein erstes Passwort
     * festgelegt: offene Einladungen der Schulen, in denen es schon als
     * Mitglied angelegt ist, gelten damit als angenommen (inkl. Einlösen
     * eines reservierten Codes). Einladungen ohne Mitgliedschaft (andere
     * Schulen, die das nie aktivierte Konto später eingeladen haben) laufen
     * weiter über den Bestätigungslink. Ein Fehler hier darf das Festlegen
     * des Passworts nicht scheitern lassen.
     */
    public function acceptForActivatedAccount(User $user): void
    {
        try {
            $invitations = TenantInvitation::on('pgsql_admin')->withoutGlobalScopes()
                ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->whereNull('accepted_at')
                ->orderBy('created_at')->get();
            $context = app(TenantContext::class);
            $previous = $context->tenant();

            foreach ($invitations as $open) {
                $isMember = TenantUser::on('pgsql_admin')->withoutGlobalScopes()
                    ->where('tenant_id', $open->tenant_id)->where('user_id', $user->id)->exists();
                $tenant = Tenant::on('pgsql_admin')->find($open->tenant_id);
                if (! $isMember || ! $tenant) {
                    continue;
                }

                // Unter dem Mandanten der Einladung (RLS), danach zurück.
                $context->set($tenant);
                try {
                    $invitation = TenantInvitation::whereKey($open->id)->whereNull('accepted_at')->first();
                    if ($invitation) {
                        $this->accept($invitation, $user);
                    }
                } finally {
                    $previous ? $context->set($previous) : $context->clear();
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ist der Code inzwischen verbraucht, gesperrt oder abgelaufen, bleibt es
     * still bei der Mitgliedschaft (die Schule kann den Kurs danach wie
     * gewohnt freischalten).
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
