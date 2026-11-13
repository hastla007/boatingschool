<?php

namespace App\Jobs;

use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\AccountSetupLink;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * Einladungsmails der Sammel-Einladung (ParticipantToolsController über
 * ParticipantInviter::queueMails()). Konten und Mitgliedschaften legt der
 * Request selbst an; hier wird nur noch verschickt -- im Mandantenkontext
 * der einladenden Schule (TenantAware).
 */
class SendInvitationMails extends MassMailJob
{
    /** @param  list<array{type: 'setup'|'invitation', user_id: string, role?: string, invitation_id?: string}>  $mails */
    public function __construct(public array $mails)
    {
        $this->initializeMassMail();
    }

    public function handle(TenantContext $context, AccountSetupLink $links): void
    {
        $tenant = $context->tenant();
        if (! $tenant) {
            return;
        }

        $failed = 0;
        foreach ($this->mails as $mail) {
            // Bestandskonten sind (noch) kein Mitglied dieser Schule und für
            // die App-Rolle im Mandantenkontext deshalb unsichtbar (RLS auf
            // app_user) -- wie ParticipantInviter über die Owner-Verbindung.
            $user = User::on('pgsql_admin')->find($mail['user_id']);
            if (! $user) {
                continue;
            }
            if ($mail['type'] === 'invitation') {
                $invitation = TenantInvitation::find($mail['invitation_id'] ?? null);
                if (! $invitation || $invitation->accepted_at !== null) {
                    continue;
                }
                $result = $user->status === 'invited'
                    ? $this->sendOnce($mail['user_id'], fn () => $links->send($user, $tenant, $invitation->role), "Einladung (Passwort festlegen) {$invitation->id}")
                    : $this->sendOnce($mail['user_id'], fn () => Notification::route('mail', $user->email)->notify(new TenantInvitationNotification($invitation)), "Einladungsmail Bestandskonto {$invitation->id}");
            } else {
                $result = $this->sendOnce($mail['user_id'], fn () => $links->send($user, $tenant, $mail['role'] ?? 'learner'), "Einladungsmail {$user->id}");
            }
            $failed += $result === false ? 1 : 0;
        }

        $this->retryIfFailed($failed);
    }
}
